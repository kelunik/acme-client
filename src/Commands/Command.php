<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\Promise;
use League\CLImate\Argument\Manager;

interface Command
{
    public function execute(Manager $args): Promise;

    public static function getDefinition(): array;
}
