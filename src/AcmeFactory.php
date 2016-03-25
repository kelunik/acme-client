<?php

namespace Kelunik\AcmeClient;

use Kelunik\Acme\AcmeClient;
use Kelunik\Acme\AcmeService;
use Kelunik\Acme\KeyPair;
use Webmozart\Assert\Assert;

class AcmeFactory {
    public function build($directory, KeyPair $keyPair) {
        Assert::string($directory);

        return new AcmeService(new AcmeClient($directory, $keyPair));
    }
}