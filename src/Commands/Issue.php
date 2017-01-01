<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\CoroutineResult;
use Amp\Dns\Record;
use Exception;
use Kelunik\Acme\AcmeException;
use Kelunik\Acme\AcmeService;
use Kelunik\Acme\KeyPair;
use Kelunik\Acme\OpenSSLKeyGenerator;
use Kelunik\AcmeClient\AcmeFactory;
use Kelunik\AcmeClient\Stores\CertificateStore;
use Kelunik\AcmeClient\Stores\ChallengeStore;
use Kelunik\AcmeClient\Stores\KeyStore;
use Kelunik\AcmeClient\Stores\KeyStoreException;
use League\CLImate\Argument\Manager;
use League\CLImate\CLImate;
use stdClass;
use Throwable;

class Issue implements Command {
    private $climate;
    private $acmeFactory;

    public function __construct(CLImate $climate, AcmeFactory $acmeFactory) {
        $this->climate = $climate;
        $this->acmeFactory = $acmeFactory;
    }

    public function execute(Manager $args) {
        return \Amp\resolve($this->doExecute($args));
    }

    private function doExecute(Manager $args) {
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            if (posix_geteuid() !== 0) {
                $processUser = posix_getpwnam(posix_geteuid());
                $currentUsername = $processUser["name"];
                $user = $args->get("user") ?: $currentUsername;

                if ($currentUsername !== $user) {
                    throw new AcmeException("Running this script with --user only works as root!");
                }
            } else {
                $user = $args->get("user") ?: "www-data";
            }
        }

        $domains = array_map("trim", explode(":", str_replace([",", ";"], ":", $args->get("domains"))));
        yield \Amp\resolve($this->checkDnsRecords($domains));

        $docRoots = explode(PATH_SEPARATOR, str_replace("\\", "/", $args->get("path")));
        $docRoots = array_map(function ($root) {
            return rtrim($root, "/");
        }, $docRoots);

        if (count($domains) < count($docRoots)) {
            throw new AcmeException("Specified more document roots than domains.");
        }

        if (count($domains) > count($docRoots)) {
            $docRoots = array_merge(
                $docRoots,
                array_fill(count($docRoots), count($domains) - count($docRoots), end($docRoots))
            );
        }

        $keyStore = new KeyStore(\Kelunik\AcmeClient\normalizePath($args->get("storage")));

        $server = \Kelunik\AcmeClient\resolveServer($args->get("server"));
        $keyFile = \Kelunik\AcmeClient\serverToKeyname($server);

        try {
            $keyPair = (yield $keyStore->get("accounts/{$keyFile}.pem"));
        } catch (KeyStoreException $e) {
            throw new AcmeException("Account key not found, did you run 'bin/acme setup'?", 0, $e);
        }

        $this->climate->br();

        $acme = $this->acmeFactory->build($server, $keyPair);
        $errors = [];

        $domainChunks = array_chunk($domains, 10, true);

        foreach ($domainChunks as $domainChunk) {
            $promises = [];

            foreach ($domainChunk as $i => $domain) {
                $promises[] = \Amp\resolve($this->solveChallenge($acme, $keyPair, $domain, $docRoots[$i]));
            }

            list($chunkErrors) = (yield \Amp\any($promises));

            $errors += $chunkErrors;
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->climate->error($error->getMessage());
            }

            throw new AcmeException("Issuance failed, not all challenges could be solved.");
        }

        $path = "certs/" . $keyFile . "/" . reset($domains) . "/key.pem";
        $bits = $args->get("bits");

        try {
            $keyPair = (yield $keyStore->get($path));
        } catch (KeyStoreException $e) {
            $keyPair = (new OpenSSLKeyGenerator)->generate($bits);
            $keyPair = (yield $keyStore->put($path, $keyPair));
        }

        $this->climate->br();
        $this->climate->whisper("    Requesting certificate ...");

        $location = (yield $acme->requestCertificate($keyPair, $domains));
        $certificates = (yield $acme->pollForCertificate($location));

        $path = \Kelunik\AcmeClient\normalizePath($args->get("storage")) . "/certs/" . $keyFile;
        $certificateStore = new CertificateStore($path);
        yield $certificateStore->put($certificates);

        $this->climate->info("    Successfully issued certificate.");
        $this->climate->info("    See {$path}/" . reset($domains));
        $this->climate->br();

        yield new CoroutineResult(0);
    }

    private function solveChallenge(AcmeService $acme, KeyPair $keyPair, $domain, $path) {
        list($location, $challenges) = (yield $acme->requestChallenges($domain));
        $goodChallenges = $this->findSuitableCombination($challenges);

        if (empty($goodChallenges)) {
            throw new AcmeException("Couldn't find any combination of challenges which this client can solve!");
        }

        $challenge = $challenges->challenges[reset($goodChallenges)];
        $token = $challenge->token;

        if (!preg_match("#^[a-zA-Z0-9-_]+$#", $token)) {
            throw new AcmeException("Protocol violation: Invalid Token!");
        }

        $payload = $acme->generateHttp01Payload($keyPair, $token);

        $this->climate->whisper("    Providing payload at http://{$domain}/.well-known/acme-challenge/{$token}");

        $challengeStore = new ChallengeStore($path);

        try {
            yield $challengeStore->put($token, $payload, isset($user) ? $user : null);

            yield $acme->verifyHttp01Challenge($domain, $token, $payload);
            yield $acme->answerChallenge($challenge->uri, $payload);
            yield $acme->pollForChallenge($location);

            $this->climate->comment("    {$domain} is now authorized.");

            yield $challengeStore->delete($token);
        } catch (Exception $e) {
            // no finally because generators...
            yield $challengeStore->delete($token);
            throw $e;
        } catch (Throwable $e) {
            // no finally because generators...
            yield $challengeStore->delete($token);
            throw $e;
        }
    }

    private function checkDnsRecords($domains) {
        $errors = [];

        $domainChunks = array_chunk($domains, 10, true);

        foreach ($domainChunks as $domainChunk) {
            $promises = [];

            foreach ($domainChunk as $domain) {
                $promises[$domain] = \Amp\Dns\resolve($domain, [
                    "types" => [Record::A, Record::AAAA],
                    "hosts" => false,
                ]);
            }

            list($chunkErrors) = (yield \Amp\any($promises));

            $errors += $chunkErrors;
        }

        if (!empty($errors)) {
            $failedDomains = implode(", ", array_keys($errors));
            $reasons = implode("\n\n", array_map(function ($exception) {
                /** @var \Exception|\Throwable $exception */
                return get_class($exception) . ": " . $exception->getMessage();
            }, $errors));

            throw new AcmeException("Couldn't resolve the following domains to an IPv4 nor IPv6 record: {$failedDomains}\n\n{$reasons}");
        }
    }

    private function findSuitableCombination(stdClass $response) {
        $challenges = isset($response->challenges) ? $response->challenges : [];
        $combinations = isset($response->combinations) ? $response->combinations : [];
        $goodChallenges = [];

        foreach ($challenges as $i => $challenge) {
            if ($challenge->type === "http-01") {
                $goodChallenges[] = $i;
            }
        }

        foreach ($goodChallenges as $i => $challenge) {
            if (!in_array([$challenge], $combinations)) {
                unset($goodChallenges[$i]);
            }
        }

        return $goodChallenges;
    }

    public static function getDefinition() {
        return [
            "server" => \Kelunik\AcmeClient\getArgumentDescription("server"),
            "storage" => \Kelunik\AcmeClient\getArgumentDescription("storage"),
            "domains" => [
                "prefix" => "d",
                "longPrefix" => "domains",
                "description" => "Colon / Semicolon / Comma separated list of domains to request a certificate for.",
                "required" => true,
            ],
            "path" => [
                "prefix" => "p",
                "longPrefix" => "path",
                "description" => "Colon (Unix) / Semicolon (Windows) separated list of paths to the document roots. The last one will be used for all remaining ones if fewer than the amount of domains is given.",
                "required" => true,
            ],
            "user" => [
                "prefix" => "u",
                "longPrefix" => "user",
                "description" => "User running the web server.",
            ],
            "bits" => [
                "longPrefix" => "bits",
                "description" => "Length of the private key in bit.",
                "defaultValue" => 2048,
                "castTo" => "int",
            ],
        ];
    }
}
