<?php

namespace Kelunik\AcmeClient;

class Config {
    private $config;

    public function __construct(array $config) {
        $this->config = $config;
    }

    public function get($key) {
        return isset($this->config[$key]) ? $this->config[$key] : null;
    }
}