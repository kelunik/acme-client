<?php

namespace Kelunik\AcmeClient\Stores;

use Amp\File\FilesystemException;
use InvalidArgumentException;
use Kelunik\Certificate\Certificate;
use Webmozart\Assert\Assert;

class CertificateStore {
    private $root;

    public function __construct($root) {
        if (!is_string($root)) {
            throw new InvalidArgumentException(sprintf("\$root must be of type string, %s given.", gettype($root)));
        }

        $this->root = rtrim(str_replace("\\", "/", $root), "/");
    }

    public function get($name) {
        return \Amp\resolve($this->doGet($name));
    }

    private function doGet($name) {
        Assert::string($name, "Name must be a string. Got: %s");

        try {
            return yield \Amp\File\get($this->root . "/" . $name . "/cert.pem");
        } catch (FilesystemException $e) {
            throw new CertificateStoreException("Failed to load certificate.", 0, $e);
        }
    }

    public function put(array $certificates) {
        return \Amp\resolve($this->doPut($certificates));
    }

    private function doPut(array $certificates) {
        if (empty($certificates)) {
            throw new InvalidArgumentException("Empty array not allowed");
        }

        $cert = new Certificate($certificates[0]);
        $commonName = $cert->getSubject()->getCommonName();

        if (!$commonName) {
            throw new CertificateStoreException("Certificate doesn't have a common name.");
        }

        // See https://github.com/amphp/dns/blob/4c4d450d4af26fc55dc56dcf45ec7977373a38bf/lib/functions.php#L83
        if (isset($commonName[253]) || !preg_match("~^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9]){0,1})(?:\\.[a-z0-9][a-z0-9-]{0,61}[a-z0-9])*$~i", $commonName)) {
            throw new CertificateStoreException("Invalid common name: '{$commonName}'");
        }

        try {
            $chain = array_slice($certificates, 1);
            $path = $this->root . "/" . $commonName;
            $realpath = realpath($path);

            if (!$realpath && !mkdir($path, 0775, true)) {
                throw new FilesystemException("Couldn't create certificate directory: '{$path}'");
            }

            yield \Amp\File\put($path . "/cert.pem", $certificates[0]);
            yield \Amp\File\chmod($path . "/cert.pem", 0644);

            yield \Amp\File\put($path . "/fullchain.pem", implode("\n", $certificates));
            yield \Amp\File\chmod($path . "/fullchain.pem", 0644);

            yield \Amp\File\put($path . "/chain.pem", implode("\n", $chain));
            yield \Amp\File\chmod($path . "/chain.pem", 0644);
        } catch (FilesystemException $e) {
            throw new CertificateStoreException("Couldn't save certificates for '{$commonName}'", 0, $e);
        }
    }

    public function delete($name) {
        return \Amp\resolve($this->doDelete($name));
    }

    private function doDelete($name) {
        Assert::string($name, "Name must be a string. Got: %s");

        foreach ((yield \Amp\File\scandir($this->root . "/" . $name)) as $file) {
            yield \Amp\File\unlink($this->root . "/" . $name . "/" . $file);
        }

        yield \Amp\File\rmdir($this->root . "/" . $name);
    }
}