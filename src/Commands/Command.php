<?php

namespace Kelunik\AcmeClient\Commands;

use League\CLImate\Argument\Manager;

interface Command {
    public function execute(Manager $args);

    public static function getDefinition();
}