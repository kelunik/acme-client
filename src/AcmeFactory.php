<?php

namespace Kelunik\AcmeClient;

use Amp\ByteStream\ResourceOutputStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Kelunik\Acme\AcmeClient;
use Kelunik\Acme\AcmeService;
use Kelunik\Acme\Crypto\PrivateKey;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

class AcmeFactory {
    public function build(string $directory, PrivateKey $keyPair): AcmeService {
        $logger = null;
        if (\getenv('ACME_LOG')) {
            $logger = new Logger('acme');
            $logger->pushProcessor(new PsrLogMessageProcessor);

            $handler = new StreamHandler(new ResourceOutputStream(\STDERR));
            $handler->setFormatter(new ConsoleFormatter(null, null, true, true));
            $logger->pushHandler($handler);
        }

        return new AcmeService(new AcmeClient($directory, $keyPair, null, null, $logger));
    }
}
