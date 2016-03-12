<?php

namespace Kelunik\AcmeClient\Commands;

use Kelunik\Certificate\Certificate;
use League\CLImate\Argument\Manager;
use Psr\Log\LoggerInterface;

class Check implements Command {
    private $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    public function execute(Manager $args) {
        return \Amp\resolve($this->doExecute($args));
    }

    /**
     * @param Manager $args
     * @return \Generator
     */
    private function doExecute(Manager $args) {
        $path = $args->get("cert");

        if (!realpath($path)) {
            throw new \RuntimeException("Certificate doesn't exist: '{$path}'");
        }

        $pem = (yield \Amp\File\get($path));
        $cert = new Certificate($pem);

        $this->logger->info("Certificate is valid until " . date("d.m.Y", $cert->getValidTo()));

        if ($cert->getValidTo() > time() + $args->get("ttl") * 24 * 60 * 60) {
            exit(0);
        }

        $this->logger->warning("Certificate is going to expire within the specified " . $args->get("ttl") . " days.");

        exit(1);
    }

    public static function getDefinition() {
        return [
            "cert" => [
                "longPrefix" => "cert",
                "prefix" => "c",
                "description" => "Certificate to check.",
                "required" => true,
            ],
            "ttl" => [
                "longPrefix" => "ttl",
                "description" => "Minimum valid time in days.",
                "defaultValue" => 30,
                "castTo" => "int",
            ],
        ];
    }
}