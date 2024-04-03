<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\Dns;
use Amp\Promise;
use Kelunik\Acme\AcmeException;
use Kelunik\Acme\AcmeService;
use Kelunik\Acme\Crypto\Backend\OpensslBackend;
use Kelunik\Acme\Crypto\PrivateKey;
use Kelunik\Acme\Crypto\RsaKeyGenerator;
use Kelunik\Acme\Csr\OpensslCsrGenerator;
use Kelunik\Acme\Protocol\Authorization;
use Kelunik\Acme\Protocol\Challenge;
use Kelunik\Acme\Protocol\Order;
use Kelunik\Acme\Verifiers\Http01;
use Kelunik\AcmeClient;
use Kelunik\AcmeClient\AcmeFactory;
use Kelunik\AcmeClient\Stores\CertificateStore;
use Kelunik\AcmeClient\Stores\ChallengeStore;
use Kelunik\AcmeClient\Stores\KeyStore;
use Kelunik\AcmeClient\Stores\KeyStoreException;
use League\CLImate\Argument\Manager;
use League\CLImate\CLImate;
use function Amp\call;
use function Kelunik\Acme\generateKeyAuthorization;
use function Kelunik\AcmeClient\getArgumentDescription;
use function Kelunik\AcmeClient\normalizePath;
use function Kelunik\AcmeClient\resolveServer;
use function Kelunik\AcmeClient\serverToKeyname;

class Issue implements Command
{
    public static function getDefinition(): array
    {
        return [
            'server' => getArgumentDescription('server'),
            'storage' => getArgumentDescription('storage'),
            'domains' => [
                'prefix' => 'd',
                'longPrefix' => 'domains',
                'description' => 'Colon / Semicolon / Comma separated list of domains to request a certificate for.',
                'required' => true,
            ],
            'path' => [
                'prefix' => 'p',
                'longPrefix' => 'path',
                'description' => 'Colon (Unix) / Semicolon (Windows) separated list of paths to the document roots. The last one will be used for all remaining ones if fewer than the amount of domains is given.',
                'required' => true,
            ],
            'user' => [
                'prefix' => 'u',
                'longPrefix' => 'user',
                'description' => 'User running the web server.',
            ],
            'bits' => [
                'longPrefix' => 'bits',
                'description' => 'Length of the private key in bit.',
                'defaultValue' => 2048,
                'castTo' => 'int',
            ],
            'challenge-concurrency' => [
                'longPrefix' => 'challenge-concurrency',
                'description' => 'Number of challenges to be solved concurrently.',
                'defaultValue' => 10,
                'castTo' => 'int',
            ],
            'rekey' => [
                'longPrefix' => 'rekey',
                'description' => 'Regenerate the key pair even if a key pair already exists.',
                'noValue' => true,
            ],
        ];
    }

    private $climate;
    private $acmeFactory;

    public function __construct(CLImate $climate, AcmeFactory $acmeFactory)
    {
        $this->climate = $climate;
        $this->acmeFactory = $acmeFactory;
    }

    public function execute(Manager $args): Promise
    {
        return call(function () use ($args) {
            $user = null;

            if (0 !== \stripos(PHP_OS, 'WIN')) {
                if (\posix_geteuid() !== 0) {
                    $processUser = \posix_getpwnam(\posix_geteuid());
                    $currentUsername = $processUser['name'];
                    $user = $args->get('user') ?: $currentUsername;

                    if ($currentUsername !== $user) {
                        throw new AcmeException('Running this script with --user only works as root!');
                    }
                } else {
                    $user = $args->get('user') ?: 'www-data';
                }
            }

            $domains = \array_map('trim', \explode(':', \str_replace([',', ';'], ':', $args->get('domains'))));
            yield from $this->checkDnsRecords($domains);

            $docRoots = \explode(PATH_SEPARATOR, \str_replace("\\", '/', $args->get('path')));
            $docRoots = \array_map(function ($root) {
                return \rtrim($root, '/');
            }, $docRoots);

            if (\count($domains) < \count($docRoots)) {
                throw new AcmeException('Specified more document roots than domains.');
            }

            if (\count($domains) > \count($docRoots)) {
                $docRoots = \array_merge(
                    $docRoots,
                    \array_fill(\count($docRoots), \count($domains) - \count($docRoots), \end($docRoots))
                );
            }

            $keyStore = new KeyStore(normalizePath($args->get('storage')));

            $server = resolveServer($args->get('server'));
            $keyFile = serverToKeyname($server);

            try {
                $key = yield $keyStore->get("accounts/{$keyFile}.pem");
            } catch (KeyStoreException $e) {
                throw new AcmeException("Account key not found, did you run 'bin/acme setup'?", 0, $e);
            }

            $this->climate->br();

            $acme = $this->acmeFactory->build($server, $key);
            $concurrency = \min(20, \max($args->get('challenge-concurrency'), 1));

            /** @var Order $order */
            $order = yield $acme->newOrder($domains);

            /** @var \Throwable[] $errors */
            [$errors] = yield AcmeClient\concurrentMap(
                $concurrency,
                $order->getAuthorizationUrls(),
                function ($authorizationUrl) use ($acme, $key, $domains, $docRoots, $user) {
                    /** @var Authorization $authorization */
                    $authorization = yield $acme->getAuthorization($authorizationUrl);

                    if ($authorization->getIdentifier()->getType() !== 'dns') {
                        throw new AcmeException('Invalid identifier: ' . $authorization->getIdentifier()->getType());
                    }

                    $name = $authorization->getIdentifier()->getValue();
                    if ($authorization->isWildcard()) {
                        $name .= '*.';
                    }

                    $index = \array_search($name, $domains, true);
                    if ($index === false) {
                        throw new AcmeException('Unknown identifier returned: ' . $name);
                    }

                    return yield from $this->solveChallenge(
                        $acme,
                        $key,
                        $authorization,
                        $name,
                        $docRoots[$index],
                        $user
                    );
                }
            );

            if ($errors) {
                foreach ($errors as $error) {
                    $this->climate->error($error->getMessage());
                }

                throw new AcmeException('Issuance failed, not all challenges could be solved.');
            }

            yield $acme->pollForOrderReady($order->getUrl());

            $keyPath = 'certs/' . $keyFile . '/' . \reset($domains) . '/key.pem';
            $bits = $args->get('bits');

            $regenerateKey = $args->get('rekey');

            try {
                $key = yield $keyStore->get($keyPath);
            } catch (KeyStoreException $e) {
                $regenerateKey = true;
            }

            if ($regenerateKey) {
                $this->climate->whisper('    Generating new key pair ...');
                $key = (new RsaKeyGenerator($bits))->generateKey();
            }

            $this->climate->br();
            $this->climate->whisper('    Requesting certificate ...');

            $csr = yield (new OpensslCsrGenerator)->generateCsr($key, $domains);

            yield $acme->finalizeOrder($order->getFinalizationUrl(), $csr);
            yield $acme->pollForOrderValid($order->getUrl());

            /** @var Order $order */
            $order = yield $acme->getOrder($order->getUrl());

            $certificates = yield $acme->downloadCertificates($order->getCertificateUrl());

            $path = normalizePath($args->get('storage')) . '/certs/' . $keyFile;
            $certificateStore = new CertificateStore($path);

            yield $keyStore->put($keyPath, $key);
            yield $certificateStore->put($certificates);

            $this->climate->info('    Successfully issued certificate.');
            $this->climate->info("    See {$path}/" . \reset($domains));
            $this->climate->br();

            return 0;
        });
    }

    private function solveChallenge(
        AcmeService $acme,
        PrivateKey $key,
        Authorization $authorization,
        string $domain,
        string $path,
        string $user = null
    ): \Generator {
        $httpChallenge = $this->findHttpChallenge($authorization);
        if ($httpChallenge === null) {
            throw new AcmeException("Couldn't find any combination of challenges which this client can solve!");
        }

        $token = $httpChallenge->getToken();
        if (!\preg_match('#^[a-zA-Z0-9-_]+$#', $token)) {
            throw new AcmeException('Protocol violation: Invalid Token!');
        }

        $payload = generateKeyAuthorization($key, $token, new OpensslBackend);

        $this->climate->whisper("    Providing payload at http://{$domain}/.well-known/acme-challenge/{$token}");

        $challengeStore = new ChallengeStore($path);

        try {
            yield $challengeStore->put($token, $payload, $user);

            yield (new Http01)->verifyChallenge($domain, $token, $payload);
            yield $acme->finalizeChallenge($httpChallenge->getUrl());
            yield $acme->pollForAuthorization($authorization->getUrl());

            $this->climate->comment("    {$domain} is now authorized.");
        } finally {
            yield $challengeStore->delete($token);
        }
    }

    private function checkDnsRecords(array $domains): \Generator
    {
        $promises = AcmeClient\concurrentMap(10, $domains, function (string $domain): Promise {
            return Dns\resolve($domain);
        });

        [$errors] = yield Promise\any($promises);

        if ($errors) {
            $failedDomains = \implode(', ', \array_keys($errors));
            $reasons = \implode("\n\n", \array_map(static function (\Throwable $exception) {
                return \get_class($exception) . ': ' . $exception->getMessage();
            }, $errors));

            throw new AcmeException("Couldn't resolve the following domains to an IPv4 nor IPv6 record: {$failedDomains}\n\n{$reasons}");
        }
    }

    private function findHttpChallenge(Authorization $authorization): ?Challenge
    {
        $challenges = $authorization->getChallenges();

        foreach ($challenges as $challenge) {
            if ($challenge->getType() === 'http-01') {
                return $challenge;
            }
        }

        return null;
    }
}
