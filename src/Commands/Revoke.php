<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\File\FilesystemException;
use Kelunik\Acme\AcmeClient;
use Kelunik\Acme\AcmeService;
use Kelunik\AcmeClient\Stores\CertificateStore;
use Kelunik\AcmeClient\Stores\KeyStore;
use Kelunik\Certificate\Certificate;
use League\CLImate\Argument\Manager;
use League\CLImate\CLImate;

class Revoke implements Command {
    private $climate;

    public function __construct(CLImate $climate) {
        $this->climate = $climate;
    }

    public function execute(Manager $args) {
        return \Amp\resolve($this->doExecute($args));
    }

    private function doExecute(Manager $args) {
        $keyStore = new KeyStore(\Kelunik\AcmeClient\normalizePath($args->get("storage")));

        $server = \Kelunik\AcmeClient\resolveServer($args->get("server"));
        $keyFile = \Kelunik\AcmeClient\serverToKeyname($server);

        $keyPair = (yield $keyStore->get("accounts/{$keyFile}.pem"));
        $acme = new AcmeService(new AcmeClient($server, $keyPair), $keyPair);

        $this->climate->info("Revoking certificate ...");

        $path = \Kelunik\AcmeClient\normalizePath($args->get("storage")) . "/certs/" . $keyFile . "/" . $args->get("name") . "/cert.pem";

        try {
            $pem = (yield \Amp\File\get($path));
            $cert = new Certificate($pem);
        } catch (FilesystemException $e) {
            throw new \RuntimeException("There's no such certificate (" . $path . ")");
        }

        if ($cert->getValidTo() < time()) {
            $this->climate->info("Certificate did already expire, no need to revoke it.");
        }

        $this->climate->info("Certificate was valid for: " . implode(", ", $cert->getNames()));
        yield $acme->revokeCertificate($pem);
        $this->climate->info("Certificate has been revoked.");

        yield (new CertificateStore(\Kelunik\AcmeClient\normalizePath($args->get("storage")). "/certs/" . $keyFile))->delete($args->get("name"));

        return 0;
    }

    public static function getDefinition() {
        $isPhar = \Kelunik\AcmeClient\isPhar();

        return [
            "server" => [
                "prefix" => "s",
                "longPrefix" => "server",
                "description" => "ACME server to use for registration and issuance of certificates.",
                "required" => true,
            ],
            "name" => [
                "longPrefix" => "name",
                "description" => "Common name of the certificate to be revoked.",
                "required" => true,
            ],
            "storage" => [
                "longPrefix" => "storage",
                "description" => "Storage directory for account keys and certificates.",
                "required" => $isPhar,
                "defaultValue" => $isPhar ? null : (__DIR__ . "/../../data")
            ]
        ];
    }
}