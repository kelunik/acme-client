<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\Dns as dns;
use Amp\Dns\Record;
use function Amp\File\put;
use Amp\Promise;
use Generator;
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
use function Amp\all;
use function Amp\any;
use function Amp\resolve;
use function Kelunik\AcmeClient\getServer;

class Issue implements Command {
    private $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    public function execute(Manager $args): Promise {
        return resolve($this->doExecute($args));
    }

    private function doExecute(Manager $args): Generator {
        $domains = array_map("trim", explode(",", $args->get("domains")));
        yield resolve($this->checkDnsRecords($domains));

        $user = $args->get("user") ?? "www-data";

        $keyStore = new KeyStore(dirname(dirname(__DIR__)) . "/data");

        $keyPair = yield $keyStore->get("account/key.pem");
        $acme = new AcmeService(new AcmeClient(getServer(), $keyPair), $keyPair);

        foreach ($domains as $domain) {
            list($location, $challenges) = yield $acme->requestChallenges($domain);
            $goodChallenges = $this->findSuitableCombination($challenges);

            if (empty($goodChallenges)) {
                throw new AcmeException("Couldn't find any combination of challenges which this client can solve!");
            }

            $challenge = $challenges->challenges[reset($goodChallenges)];
            $token = $challenge->token;

            if (!preg_match("#^[a-zA-Z0-9-_]+$#", $token)) {
                throw new AcmeException("Protocol Violation: Invalid Token!");
            }

            $this->logger->debug("Generating payload...");
            $payload = $acme->generateHttp01Payload($token);

            $this->logger->info("Providing payload at http://{$domain}/.well-known/acme-challenge/{$token}");
            $docRoot = rtrim(str_replace("\\", "/", $args->get("path")), "/");

            $challengeStore = new ChallengeStore($docRoot);

            try {
                $challengeStore->put($token, $payload, $user);

                yield $acme->selfVerify($domain, $token, $payload);
                $this->logger->info("Successfully self-verified challenge.");

                yield $acme->answerChallenge($challenge->uri, $payload);
                $this->logger->info("Answered challenge... waiting");

                yield $acme->pollForChallenge($location);
                $this->logger->info("Challenge successful. {$domain} is now authorized.");

                yield $challengeStore->delete($token);
            } catch (Throwable $e) {
                // no finally because generators...
                yield $challengeStore->delete($token);
                throw $e;
            }
        }

        $path = "certs/" . reset($domains) . "/key.pem";
        $bits = $args->get("bits") ?? 2048;

        try {
            $keyPair = yield $keyStore->get($path);
        } catch (KeyStoreException $e) {
            $keyPair = (new OpenSSLKeyGenerator)->generate($bits);
            $keyPair = yield $keyStore->put($path, $keyPair);
        }

        $this->logger->info("Requesting certificate ...");

        $location = yield $acme->requestCertificate($keyPair, $domains);
        $certificates = yield $acme->pollForCertificate($location);

        $path = dirname(dirname(__DIR__)) . "/data/certs";
        $certificateStore = new CertificateStore($path);
        yield $certificateStore->put($certificates);

        yield put($path . "/" . reset($domains) . "/config.json", json_encode([
            "domains" => $domains, "path" => $args->get("path"), "user" => $user, "bits" => $bits
        ], JSON_PRETTY_PRINT) . "\n");

        $this->logger->info("Successfully issued certificate, see {$path}/" . reset($domains));
    }

    private function checkDnsRecords($domains): Generator {
        $promises = [];

        foreach ($domains as $domain) {
            $promises[$domain] = dns\resolve($domain, [
                "types" => [Record::A],
                "hosts" => false,
            ]);
        }

        list($errors) = yield any($promises);

        if (!empty($errors)) {
            throw new AcmeException("Couldn't resolve the following domains to an IPv4 record: " . implode(array_keys($errors)));
        }

        $this->logger->info("Checked DNS records, all fine.");
    }

    private function findSuitableCombination(stdClass $response): array {
        $challenges = $response->challenges ?? [];
        $combinations = $response->combinations ?? [];
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

    public static function getDefinition(): array {
        return [
            "domains" => [
                "prefix" => "d",
                "longPrefix" => "domains",
                "description" => "Comma separated list of domains to request a certificate for.",
                "required" => true,
            ],
            "path" => [
                "prefix" => "p",
                "longPrefix" => "path",
                "description" => "Absolute path to the document root of these domains.",
                "required" => true,
            ],
            "user" => [
                "prefix" => "u",
                "longPrefix" => "user",
                "description" => "User running the web server.",
                "defaultValue" => "www-data",
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