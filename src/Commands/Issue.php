<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\Dns\Record;
use Exception;
use Kelunik\Acme\AcmeClient;
use Kelunik\Acme\AcmeException;
use Kelunik\Acme\AcmeService;
use Kelunik\Acme\OpenSSLKeyGenerator;
use Kelunik\AcmeClient\Stores\CertificateStore;
use Kelunik\AcmeClient\Stores\ChallengeStore;
use Kelunik\AcmeClient\Stores\KeyStore;
use Kelunik\AcmeClient\Stores\KeyStoreException;
use League\CLImate\Argument\Manager;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

class Issue implements Command {
    private $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
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

        $domains = array_map("trim", explode(":", str_replace(",", ":", $args->get("domains"))));
        yield \Amp\resolve($this->checkDnsRecords($domains));

        $docRoots = explode(":", str_replace("\\", "/", $args->get("path")));
        $docRoots = array_map(function($root) {
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

        $keyStore = new KeyStore(dirname(dirname(__DIR__)) . "/data");

        $server = \Kelunik\AcmeClient\resolveServer($args->get("server"));
        $keyFile = \Kelunik\AcmeClient\serverToKeyname($server);

        try {
            $keyPair = (yield $keyStore->get("accounts/{$keyFile}.pem"));
        } catch (KeyStoreException $e) {
            $this->logger->error("Account key not found, did you run 'bin/acme setup'?");

            exit(1);
        }

        $acme = new AcmeService(new AcmeClient($server, $keyPair));

        foreach ($domains as $i => $domain) {
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

            $this->logger->debug("Generating payload...");
            $payload = $acme->generateHttp01Payload($keyPair, $token);

            $this->logger->info("Providing payload at http://{$domain}/.well-known/acme-challenge/{$token}");


            $challengeStore = new ChallengeStore($docRoots[$i]);

            try {
                $challengeStore->put($token, $payload, isset($user) ? $user : null);

                yield $acme->verifyHttp01Challenge($domain, $token, $payload);
                $this->logger->info("Successfully self-verified challenge.");

                yield $acme->answerChallenge($challenge->uri, $payload);
                $this->logger->info("Answered challenge... waiting");

                yield $acme->pollForChallenge($location);
                $this->logger->info("Challenge successful. {$domain} is now authorized.");

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

        $path = "certs/" . $keyFile . "/" . reset($domains) . "/key.pem";
        $bits = $args->get("bits");

        try {
            $keyPair = (yield $keyStore->get($path));
        } catch (KeyStoreException $e) {
            $keyPair = (new OpenSSLKeyGenerator)->generate($bits);
            $keyPair = (yield $keyStore->put($path, $keyPair));
        }

        $this->logger->info("Requesting certificate ...");

        $location = (yield $acme->requestCertificate($keyPair, $domains));
        $certificates = (yield $acme->pollForCertificate($location));

        $path = dirname(dirname(__DIR__)) . "/data/certs/" . $keyFile;
        $certificateStore = new CertificateStore($path);
        yield $certificateStore->put($certificates);

        $this->logger->info("Successfully issued certificate, see {$path}/" . reset($domains));
    }

    private function checkDnsRecords($domains) {
        $promises = [];

        foreach ($domains as $domain) {
            $promises[$domain] = \Amp\Dns\resolve($domain, [
                "types" => [Record::A],
                "hosts" => false,
            ]);
        }

        list($errors) = (yield \Amp\any($promises));

        if (!empty($errors)) {
            throw new AcmeException("Couldn't resolve the following domains to an IPv4 record: " . implode(array_keys($errors)));
        }

        $this->logger->info("Checked DNS records, all fine.");
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
            "server" => [
                "prefix" => "s",
                "longPrefix" => "server",
                "description" => "Server to use for issuance, see also 'bin/acme setup'.",
                "required" => true,
            ],
            "domains" => [
                "prefix" => "d",
                "longPrefix" => "domains",
                "description" => "Colon separated list of domains to request a certificate for.",
                "required" => true,
            ],
            "path" => [
                "prefix" => "p",
                "longPrefix" => "path",
                "description" => "Colon separated list of paths to the document roots. The last one will be used for all remaining ones if fewer than the amount of domains is given.",
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