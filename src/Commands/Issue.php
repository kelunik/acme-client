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

class Issue implements Command {
    private $climate;
    private $acmeFactory;

    public function __construct(CLImate $climate, AcmeFactory $acmeFactory) {
        $this->climate = $climate;
        $this->acmeFactory = $acmeFactory;
    }

    public function execute(Manager $args): Promise {
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

            $keyStore = new KeyStore(\Kelunik\AcmeClient\normalizePath($args->get('storage')));

            $server = \Kelunik\AcmeClient\resolveServer($args->get('server'));
            $keyFile = \Kelunik\AcmeClient\serverToKeyname($server);

            try {
                $key = yield $keyStore->get("accounts/{$keyFile}.pem");
            } catch (KeyStoreException $e) {
                throw new AcmeException("Account key not found, did you run 'bin/acme setup'?", 0, $e);
            }

            $this->climate->br();

            $acme = $this->acmeFactory->build($server, $key);
            $concurrency = \min(20, \max($args->get('challenge-concurrency'), 1));

            /** @var \Throwable[] $errors */
            list($errors) = yield AcmeClient\concurrentMap($concurrency, $domains, function ($domain, $i) use ($acme, $key, $docRoots, $user) {
                return $this->solveChallenge($acme, $key, $domain, $docRoots[$i], $user);
            });

            if ($errors) {
                foreach ($errors as $error) {
                    $this->climate->error($error->getMessage());
                }

                throw new AcmeException('Issuance failed, not all challenges could be solved.');
            }

            $path = 'certs/' . $keyFile . '/' . \reset($domains) . '/key.pem';
            $bits = $args->get('bits');

            try {
                $key = yield $keyStore->get($path);
            } catch (KeyStoreException $e) {
                $key = (new RsaKeyGenerator($bits))->generateKey();
                $key = yield $keyStore->put($path, $key);
            }

            $this->climate->br();
            $this->climate->whisper('    Requesting certificate ...');

            $csr = (new OpensslCsrGenerator)->generateCsr($key, $domains);

            $location = yield $acme->requestCertificate($csr);
            $certificates = yield $acme->pollForCertificate($location);

            $path = AcmeClient\normalizePath($args->get('storage')) . '/certs/' . $keyFile;
            $certificateStore = new CertificateStore($path);
            yield $certificateStore->put($certificates);

            $this->climate->info('    Successfully issued certificate.');
            $this->climate->info("    See {$path}/" . \reset($domains));
            $this->climate->br();

            return 0;
        });
    }

    private function solveChallenge(AcmeService $acme, PrivateKey $key, string $domain, string $path, string $user = null): \Generator {
        list($location, $challenges) = yield $acme->requestChallenges($domain);
        $goodChallenges = $this->findSuitableCombination($challenges);

        if (empty($goodChallenges)) {
            throw new AcmeException("Couldn't find any combination of challenges which this client can solve!");
        }

        $challenge = $challenges->challenges[\reset($goodChallenges)];
        $token = $challenge->token;

        if (!\preg_match('#^[a-zA-Z0-9-_]+$#', $token)) {
            throw new AcmeException('Protocol violation: Invalid Token!');
        }

        $payload = generateKeyAuthorization($key, $token, new OpensslBackend);

        $this->climate->whisper("    Providing payload at http://{$domain}/.well-known/acme-challenge/{$token}");

        $challengeStore = new ChallengeStore($path);

        try {
            yield $challengeStore->put($token, $payload, $user);

            yield (new Http01)->verifyChallenge($domain, $token, $payload);
            yield $acme->answerChallenge($challenge->uri, $payload);
            yield $acme->pollForChallenge($location);

            $this->climate->comment("    {$domain} is now authorized.");
        } finally {
            yield $challengeStore->delete($token);
        }
    }

    private function checkDnsRecords(array $domains): \Generator {
        $promises = AcmeClient\concurrentMap(10, $domains, function (string $domain): Promise {
            return Dns\resolve($domain);
        });

        list($errors) = yield Promise\any($promises);

        if ($errors) {
            $failedDomains = \implode(', ', \array_keys($errors));
            $reasons = \implode("\n\n", \array_map(function ($exception) {
                /** @var \Throwable $exception */
                return \get_class($exception) . ': ' . $exception->getMessage();
            }, $errors));

            throw new AcmeException("Couldn't resolve the following domains to an IPv4 nor IPv6 record: {$failedDomains}\n\n{$reasons}");
        }
    }

    private function findSuitableCombination(\stdClass $response): array {
        $challenges = $response->challenges ?? [];
        $combinations = $response->combinations ?? [];
        $goodChallenges = [];

        foreach ($challenges as $i => $challenge) {
            if ($challenge->type === 'http-01') {
                $goodChallenges[] = $i;
            }
        }

        foreach ($goodChallenges as $i => $challenge) {
            if (!\in_array([$challenge], $combinations, true)) {
                unset($goodChallenges[$i]);
            }
        }

        return $goodChallenges;
    }

    public static function getDefinition(): array {
        return [
            'server' => AcmeClient\getArgumentDescription('server'),
            'storage' => AcmeClient\getArgumentDescription('storage'),
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
        ];
    }
}
