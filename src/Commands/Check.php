<?php

namespace Kelunik\AcmeClient\Commands;

use Kelunik\AcmeClient\Stores\CertificateStore;
use Kelunik\Certificate\Certificate;
use League\CLImate\Argument\Manager;
use League\CLImate\CLImate;

class Check implements Command {
    private $climate;

    public function __construct(CLImate $climate) {
        $this->climate = $climate;
    }

    public function execute(Manager $args) {
        return \Amp\resolve($this->doExecute($args));
    }

    /**
     * @param Manager $args
     * @return \Generator
     */
    private function doExecute(Manager $args) {
        $server = \Kelunik\AcmeClient\resolveServer($args->get("server"));
        $server = \Kelunik\AcmeClient\serverToKeyname($server);

        $path = \Kelunik\AcmeClient\normalizePath($args->get("storage")) . "/certs/" . $server;
        $certificateStore = new CertificateStore($path);

        $pem = (yield $certificateStore->get($args->get("name")));
        $cert = new Certificate($pem);

        $this->climate->info("Certificate is valid until " . date("d.m.Y", $cert->getValidTo()));

        if ($cert->getValidTo() > time() + $args->get("ttl") * 24 * 60 * 60) {
            return 0;
        }

        $this->climate->comment("Certificate is going to expire within the specified " . $args->get("ttl") . " days.");

        return 1;
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
                "description" => "Common name of the certificate to check.",
                "required" => true,
            ],
            "ttl" => [
                "longPrefix" => "ttl",
                "description" => "Minimum valid time in days.",
                "defaultValue" => 30,
                "castTo" => "int",
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