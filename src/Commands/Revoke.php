<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\Promise;
use Generator;
use Kelunik\Acme\AcmeClient;
use Kelunik\Acme\AcmeException;
use Kelunik\Acme\AcmeService;
use Kelunik\Acme\KeyPair;
use Kelunik\Certificate\Certificate;
use League\CLImate\Argument\Manager;
use Psr\Log\LoggerInterface;
use function Amp\File\exists;
use function Amp\File\get;
use function Amp\resolve;

class Revoke implements Command {
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

        $server = $args->get("server");
        $protocol = substr($server, 0, strpos("://", $server));

        if (!$protocol || $protocol === $server) {
            $server = "https://" . $server;
        } elseif ($protocol !== "https") {
            throw new \InvalidArgumentException("Invalid server protocol, only HTTPS supported");
        }

        $keyPair = $this->checkRegistration($args);
        $acme = new AcmeService(new AcmeClient($server, $keyPair), $keyPair);

        $this->logger->info("Revoking certificate ...");

        $pem = yield get($args->get("cert"));
        $cert = new Certificate($pem);

        if ($cert->getValidTo() < time()) {
            $this->logger->warning("Certificate did already expire, no need to revoke it.");
            return;
        }

        $this->logger->info("Certificate was valid for: " . implode(", ", $cert->getNames()));

        yield $acme->revokeCertificate($pem);

        $this->logger->info("Certificate has been revoked.");
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

    public static function getDefinition(): array {
        return [
            "cert" => [
                "prefix" => "c",
                "longPrefix" => "cert",
                "description" => "Certificate to be revoked.",
                "required" => true,
            ],
            "server" => [
                "prefix" => "s",
                "longPrefix" => "server",
                "description" => "ACME server to use for authorization.",
                "required" => true,
            ],
        ];
    }
}