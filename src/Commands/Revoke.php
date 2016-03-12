<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\File\FilesystemException;
use Amp\Promise;
use Generator;
use Kelunik\Acme\AcmeClient;
use Kelunik\Acme\AcmeException;
use Kelunik\Acme\AcmeService;
use Kelunik\AcmeClient\Stores\CertificateStore;
use Kelunik\AcmeClient\Stores\KeyStore;
use Kelunik\Certificate\Certificate;
use League\CLImate\Argument\Manager;
use Psr\Log\LoggerInterface;

class Revoke implements Command {
    private $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    public function execute(Manager $args) {
        return \Amp\resolve($this->doExecute($args));
    }

    private function doExecute(Manager $args) {
        $keyStore = new KeyStore(dirname(dirname(__DIR__)) . "/data");

        $server = $args->get("server");
        $keyFile = \Kelunik\AcmeClient\serverToKeyname($server);

        $keyPair = (yield $keyStore->get("accounts/{$keyFile}.pem"));
        $acme = new AcmeService(new AcmeClient($server, $keyPair), $keyPair);

        $this->logger->info("Revoking certificate ...");

        try {
            $pem = (yield \Amp\File\get(dirname(dirname(__DIR__)) . "/data/certs/" . $args->get("name") . "/cert.pem"));
            $cert = new Certificate($pem);
        } catch (FilesystemException $e) {
            throw new \RuntimeException("There's no such certificate!");
        }

        if ($cert->getValidTo() < time()) {
            $this->logger->warning("Certificate did already expire, no need to revoke it.");
        }

        $this->logger->info("Certificate was valid for: " . implode(", ", $cert->getNames()));
        yield $acme->revokeCertificate($pem);
        $this->logger->info("Certificate has been revoked.");

        yield (new CertificateStore(dirname(dirname(__DIR__)) . "/data/certs"))->delete($args->get("name"));
    }

    public static function getDefinition() {
        return [
            "server" => [
                "prefix" => "s",
                "longPrefix" => "server",
                "description" => "",
                "required" => true,
            ],
            "name" => [
                "longPrefix" => "name",
                "description" => "Common name of the certificate to be revoked.",
                "required" => true,
            ],
        ];
    }
}