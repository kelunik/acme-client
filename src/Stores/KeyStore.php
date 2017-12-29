<?php

namespace Kelunik\AcmeClient\Stores;

use Amp\File;
use Amp\File\FilesystemException;
use Amp\Promise;
use Kelunik\Acme\Crypto\PrivateKey;
use function Amp\call;

class KeyStore {
    private $root;

    public function __construct(string $root = '') {
        $this->root = \rtrim(\str_replace("\\", '/', $root), '/');
    }

    public function get(string $path): Promise {
        return call(function () use ($path) {
            $file = $this->root . '/' . $path;
            $privateKey = yield File\get($file);

            // Check key here to be valid, PrivateKey doesn't do that, we fail early here
            $res = \openssl_pkey_get_private($privateKey);

            if ($res === false) {
                throw new KeyStoreException("Invalid private key: '{$file}'");
            }

            return new PrivateKey($privateKey);
        });
    }

    public function put(string $path, PrivateKey $key): Promise {
        return call(function () use ($path, $key) {
            $file = $this->root . '/' . $path;

            try {
                $dir = \dirname($file);

                if (!yield File\isdir($dir) && !yield File\mkdir($dir, 0644, true) && !yield File\isdir($dir)) {
                    throw new FilesystemException("Couldn't create key directory: '{$path}'");
                }

                yield File\put($file, $key->toPem());
                yield File\chmod($file, 0600);
            } catch (FilesystemException $e) {
                throw new KeyStoreException('Could not save key: ' . $e->getMessage(), 0, $e);
            }

            return $key;
        });
    }
}
