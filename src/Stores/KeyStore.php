<?php

namespace Kelunik\AcmeClient\Stores;

use Amp\File;
use Amp\File\FilesystemException;
use Amp\Promise;
use Kelunik\Acme\Crypto\PrivateKey;
use function Amp\call;

class KeyStore
{
    private $root;

    public function __construct(string $root = '')
    {
        $this->root = \rtrim(\str_replace("\\", '/', $root), '/');
    }

    public function get(string $path): Promise
    {
        return call(function () use ($path) {
            $file = $this->root . '/' . $path;

            try {
                $privateKey = yield File\read($file);

                // Check key here to be valid, PrivateKey doesn't do that, we fail early here
                $res = \openssl_pkey_get_private($privateKey);

                if ($res === false) {
                    throw new KeyStoreException("Invalid private key: '{$file}'");
                }

                return new PrivateKey($privateKey);
            } catch (FilesystemException $e) {
                throw new KeyStoreException("Key not found: '{$file}'");
            }
        });
    }

    public function put(string $path, PrivateKey $key): Promise
    {
        return call(function () use ($path, $key) {
            $file = $this->root . '/' . $path;

            try {
                $dir = \dirname($file);

                yield File\createDirectoryRecursively($dir, 0755);
                yield File\write($file, $key->toPem());
                yield File\changeOwner($file, 0600);
            } catch (FilesystemException $e) {
                throw new KeyStoreException('Could not save key: ' . $e->getMessage(), 0, $e);
            }

            return $key;
        });
    }
}
