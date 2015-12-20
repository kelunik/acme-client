<?php

namespace Kelunik\AcmeClient;

use RuntimeException;

class Configuration {
    private $config;

    public function __construct($file) {
        $json = file_get_contents($file);

        if (!$json) {
            throw new RuntimeException("Couldn't read config file: '{$file}'");
        }

        $this->config = json_decode($json);

        if (!$this->config) {
            throw new RuntimeException("Couldn't read JSON: '{$json}'");
        }
    }

    public function get($key) {
        return isset($this->config->{$key}) ? $this->config->{$key} : null;
    }
}