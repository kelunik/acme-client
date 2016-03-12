<?php

namespace Kelunik\AcmeClient\Stores;

use Amp\CoroutineResult;
use Amp\File\FilesystemException;
use InvalidArgumentException;
use Kelunik\Acme\KeyPair;

class KeyStore {
    private $root;

    public function __construct($root = "") {
        if (!is_string($root)) {
            throw new InvalidArgumentException(sprintf("\$root must be of type string, %s given.", gettype($root)));
        }

        $this->root = rtrim(str_replace("\\", "/", $root), "/");
    }

    public function get($path) {
        if (!is_string($path)) {
            throw new InvalidArgumentException(sprintf("\$root must be of type string, %s given.", gettype($path)));
        }

        return \Amp\resolve($this->doGet($path));
    }

    private function doGet($path) {
        if (!is_string($path)) {
            throw new InvalidArgumentException(sprintf("\$root must be of type string, %s given.", gettype($path)));
        }

        $file = $this->root . "/" . $path;
        $realpath = realpath($file);

        if (!$realpath) {
            throw new KeyStoreException("File not found: '{$file}'");
        }

        $privateKey = (yield \Amp\File\get($realpath));
        $res = openssl_pkey_get_private($privateKey);

        if ($res === false) {
            throw new KeyStoreException("Invalid private key: '{$file}'");
        }

        $publicKey = openssl_pkey_get_details($res)["key"];

        yield new CoroutineResult(new KeyPair($privateKey, $publicKey));
    }

    public function put($path, KeyPair $keyPair) {
        if (!is_string($path)) {
            throw new InvalidArgumentException(sprintf("\$root must be of type string, %s given.", gettype($path)));
        }

        return \Amp\resolve($this->doPut($path, $keyPair));
    }

    private function doPut($path, KeyPair $keyPair) {
        if (!is_string($path)) {
            throw new InvalidArgumentException(sprintf("\$root must be of type string, %s given.", gettype($path)));
        }

        $file = $this->root . "/" . $path;

        try {
            // TODO: Replace with async version once available
            if (!file_exists($file)) {
                mkdir(dirname($file), 0755, true);
            }

            yield \Amp\File\put($file, $keyPair->getPrivate());
            yield \Amp\File\chmod($file, 0600);
        } catch (FilesystemException $e) {
            throw new KeyStoreException("Could not save key.", 0, $e);
        }

        yield new CoroutineResult($keyPair);
    }
}