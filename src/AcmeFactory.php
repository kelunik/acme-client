<?php

namespace Kelunik\AcmeClient;

use Kelunik\Acme\AcmeClient;
use Kelunik\Acme\AcmeService;
use Kelunik\Acme\Crypto\PrivateKey;

class AcmeFactory {
    public function build(string $directory, PrivateKey $keyPair): AcmeService {
        return new AcmeService(new AcmeClient($directory, $keyPair));
    }
}