<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\Promise;
use Kelunik\Acme\AcmeException;
use League\CLImate\Argument\Manager;

class Revoke implements Command {
    public function execute(Manager $args): Promise {
        throw new AcmeException("Command not yet implemented!");
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
        ];
    }
}