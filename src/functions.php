<?php

namespace Kelunik\AcmeClient;

function commandToClass($command) {
    return __NAMESPACE__ . "\\Commands\\" . ucfirst($command);
}

function getServer(Configuration $config = null) {
    if ($config === null) {
        $path = dirname(__DIR__) . "/data";
        $config = new Configuration($path . "/account/config.json");
    }

    return $config->get("server");
}