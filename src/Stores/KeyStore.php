<?php

namespace Kelunik\AcmeClient\Stores;

use Amp\File\FilesystemException;
use Amp\Promise;
use Generator;
use Kelunik\Acme\KeyPair;
use function Amp\File\chmod;
use function Amp\File\chown;
use function Amp\File\get;
use function Amp\File\put;
use function Amp\resolve;

class KeyStore {
    private $root;

    public function __construct(string $root = "") {
        $this->root = rtrim(str_replace("\\", "/", $root), "/");
    }

    public function get(string $path): Promise {
        return resolve($this->doGet($path));
    }

    private function doGet(string $path): Generator {
        $file = $this->root . "/" . $path;
        $realpath = realpath($file);

        if (!$realpath) {
            throw new KeyStoreException("File not found: '{$file}'");
        }

        $privateKey = yield get($realpath);
        $res = openssl_pkey_get_private($privateKey);

        if ($res === false) {
            throw new KeyStoreException("Invalid private key: '{$file}'");
        }

        $publicKey = openssl_pkey_get_details($res)["key"];

        return new KeyPair($privateKey, $publicKey);
    }

    public function put(string $path, KeyPair $keyPair): Promise {
        return resolve($this->doPut($path, $keyPair));
    }

    private function doPut(string $path, KeyPair $keyPair): Generator {
        $file = $this->root . "/" . $path;

        try {
            // TODO: Replace with async version once available
            mkdir(dirname($file), 0770, true);

            yield put($file, $keyPair->getPrivate());
            yield chmod($file, 0600);
            yield chown($file, 0, 0);
        } catch (FilesystemException $e) {
            throw new KeyStoreException("Could not save key.", 0, $e);
        }

        return $keyPair;
    }
}