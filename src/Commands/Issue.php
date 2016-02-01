<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\Dns as dns;
use Amp\Dns\Record;
use Amp\Promise;
use Generator;
use Kelunik\Acme\AcmeClient;
use Kelunik\Acme\AcmeException;
use Kelunik\Acme\AcmeService;
use Kelunik\Acme\KeyPair;
use Kelunik\Acme\OpenSSLKeyGenerator;
use League\CLImate\Argument\Manager;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;
use function Amp\all;
use function Amp\any;
use function Amp\resolve;

class Issue implements Command {
    private $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    public function execute(Manager $args): Promise {
        return resolve($this->doExecute($args));
    }

    private function doExecute(Manager $args): Generator {
        if (posix_geteuid() !== 0) {
            throw new AcmeException("Please run this script as root!");
        }

        $user = $args->get("user") ?? "www-data";

        $server = $args->get("server");
        $protocol = substr($server, 0, strpos("://", $server));

        if (!$protocol || $protocol === $server) {
            $server = "https://" . $server;
        } elseif ($protocol !== "https") {
            throw new \InvalidArgumentException("Invalid server protocol, only HTTPS supported");
        }

        $domains = $args->get("domains");
        $domains = array_map("trim", explode(",", $domains));
        yield from $this->checkDnsRecords($domains);

        $keyPair = $this->checkRegistration($args);

        $acme = new AcmeService(new AcmeClient($server, $keyPair), $keyPair);

        foreach ($domains as $domain) {
            list($location, $challenges) = yield $acme->requestChallenges($domain);
            $goodChallenges = $this->findSuitableCombination($challenges);

            if (empty($goodChallenges)) {
                throw new AcmeException("Couldn't find any combination of challenges which this server can solve!");
            }

            $challenge = $challenges->challenges[reset($goodChallenges)];
            $token = $challenge->token;

            if (!preg_match("#^[a-zA-Z0-9-_]+$#", $token)) {
                throw new AcmeException("Protocol Violation: Invalid Token!");
            }

            $this->logger->debug("Generating payload...");
            $payload = $acme->generateHttp01Payload($token);

            $docRoot = rtrim($args->get("path") ?? __DIR__ . "/../../data/public", "/\\");
            $path = $docRoot . "/.well-known/acme-challenge";

            try {
                if (!file_exists($docRoot)) {
                    throw new AcmeException("Document root doesn't exist: " . $docRoot);
                }

                if (!file_exists($path) && !@mkdir($path, 0770, true)) {
                    throw new AcmeException("Couldn't create public dir to serve the challenges: " . $path);
                }

                if (!$userInfo = posix_getpwnam($user)) {
                    throw new AcmeException("Unknown user: " . $user);
                }

                chown($docRoot . "/.well-known", $userInfo["uid"]);
                chown($docRoot . "/.well-known/acme-challenge", $userInfo["uid"]);

                $this->logger->info("Providing payload for {$domain} at {$path}/{$token}");

                file_put_contents("{$path}/{$token}", $payload);
                chown("{$path}/{$token}", $userInfo["uid"]);
                chmod("{$path}/{$token}", 0660);

                yield $acme->selfVerify($domain, $token, $payload);
                $this->logger->info("Successfully self-verified challenge.");

                yield $acme->answerChallenge($challenge->uri, $payload);
                $this->logger->info("Answered challenge... waiting");

                yield $acme->pollForChallenge($location);
                $this->logger->info("Challenge successful. {$domain} is now authorized.");

                @unlink("{$path}/{$token}");
            } catch (Throwable $e) {
                // no finally because generators...
                @unlink("{$path}/{$token}");
                throw $e;
            }
        }

        $path = __DIR__ . "/../../data/live/" . $args->get("file") ?? current($domains);

        if (!file_exists($path) && !mkdir($path, 0700, true)) {
            throw new AcmeException("Couldn't create directory: {$path}");
        }

        if (file_exists($path . "/private.pem") && file_exists($path . "/public.pem")) {
            $private = file_get_contents($path . "/private.pem");
            $public = file_get_contents($path . "/public.pem");

            $this->logger->info("Using existing domain key found at {$path}");

            $domainKeys = new KeyPair($private, $public);
        } else {
            $domainKeys = (new OpenSSLKeyGenerator)->generate(2048);

            file_put_contents($path . "/private.pem", $domainKeys->getPrivate());
            file_put_contents($path . "/public.pem", $domainKeys->getPublic());

            $this->logger->info("Saved new domain key at {$path}");

            chmod($path . "/private.pem", 0600);
            chmod($path . "/public.pem", 0600);
        }

        $this->logger->info("Requesting certificate ...");

        $location = yield $acme->requestCertificate($domainKeys, $domains);
        $certificates = yield $acme->pollForCertificate($location);

        $this->logger->info("Saving certificate ...");

        file_put_contents($path . "/cert.pem", reset($certificates));
        file_put_contents($path . "/fullchain.pem", implode("\n", $certificates));

        array_shift($certificates);
        file_put_contents($path . "/chain.pem", implode("\n", $certificates));

        $this->logger->info("Successfully issued certificate.");
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

    private function checkRegistration(Manager $args) {
        $server = $args->get("server");
        $protocol = substr($server, 0, strpos("://", $server));

        if (!$protocol || $protocol === $server) {
            $server = "https://" . $server;
        } elseif ($protocol !== "https") {
            throw new \InvalidArgumentException("Invalid server protocol, only HTTPS supported");
        }

        $identity = str_replace(["/", "%"], "-", substr($server, 8));

        $path = __DIR__ . "/../../data/accounts";
        $pathPrivate = "{$path}/{$identity}.private.key";
        $pathPublic = "{$path}/{$identity}.public.key";

        if (file_exists($pathPrivate) && file_exists($pathPublic)) {
            $private = file_get_contents($pathPrivate);
            $public = file_get_contents($pathPublic);

            $this->logger->info("Found account keys.");

            return new KeyPair($private, $public);
        }

        throw new AcmeException("No registration found for server, please register first");
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
                "description" => "Domains to request a certificate for.",
                "required" => true,
            ],
            "server" => [
                "prefix" => "s",
                "longPrefix" => "server",
                "description" => "ACME server to use for authorization.",
                "required" => true,
            ],
            "user" => [
                "prefix" => "s",
                "longPrefix" => "user",
                "description" => "User for the public directory.",
                "required" => false,
            ],
            "path" => [
                "prefix" => "p",
                "longPrefix" => "path",
                "description" => "Path to the document root for ACME challenges.",
                "required" => false,
            ],
            "file" => [
                "prefix" => "f",
                "longPrefix" => "file",
                "descript" => "Output filename",
                "required" => false,
            ]
        ];
    }
}