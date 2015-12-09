<?php

namespace Kelunik\AcmeClient\Stores;

use Amp\File\FilesystemException;
use Amp\Promise;
use Generator;
use InvalidArgumentException;
use Kelunik\Certificate\Certificate;
use function Amp\File\put;
use function Amp\File\chmod;
use function Amp\File\chown;
use function Amp\File\scandir;
use function Amp\File\unlink;
use function Amp\resolve;
use function Amp\File\rmdir;

class CertificateStore {
    private $root;

    public function __construct(string $root) {
        $this->root = rtrim(str_replace("\\", "/", $root), "/");
    }

    public function put(array $certificates): Promise {
        return resolve($this->doPut($certificates));
    }

    private function doPut(array $certificates): Generator {
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

            if (!$realpath && !mkdir($path, 0770, true)) {
                throw new FilesystemException("Couldn't create certificate directory: '{$path}'");
            }

            yield put($path . "/cert.pem", $certificates[0]);
            yield chown($path . "/cert.pem", 0, 0);
            yield chmod($path . "/cert.pem", 0640);

            yield put($path . "/fullchain.pem", implode("\n", $certificates));
            yield chown($path . "/fullchain.pem", 0, 0);
            yield chmod($path . "/fullchain.pem", 0640);

            yield put($path . "/chain.pem", implode("\n", $chain));
            yield chown($path . "/chain.pem", 0, 0);
            yield chmod($path . "/chain.pem", 0640);
        } catch (FilesystemException $e) {
            throw new CertificateStoreException("Couldn't save certificates for '{$commonName}'", 0, $e);
        }
    }

    public function delete(string $name): Promise {
        return resolve($this->doDelete($name));
    }

    private function doDelete(string $name): Generator {
        foreach ((yield scandir($this->root . "/" . $name)) as $file) {
            yield unlink($this->root . "/" . $name . "/" . $file);
        }

        yield rmdir($this->root . "/" . $name);
    }
}