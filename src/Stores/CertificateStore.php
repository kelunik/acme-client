<?php

namespace Kelunik\AcmeClient\Stores;

use Amp\File;
use Amp\File\FilesystemException;
use Amp\Promise;
use Kelunik\Certificate\Certificate;
use function Amp\call;
use function Amp\Dns\isValidName;

class CertificateStore
{
    private $root;

    public function __construct(string $root)
    {
        $this->root = \rtrim(\str_replace("\\", '/', $root), '/');
    }

    public function get(string $name): Promise
    {
        return call(function () use ($name) {
            try {
                return yield File\read($this->root . '/' . $name . '/cert.pem');
            } catch (FilesystemException $e) {
                throw new CertificateStoreException('Failed to load certificate.', 0, $e);
            }
        });
    }

    public function put(array $certificates): Promise
    {
        return call(function () use ($certificates) {
            if (empty($certificates)) {
                throw new \Error('Empty array not allowed');
            }

            $cert = new Certificate($certificates[0]);
            $commonName = $cert->getSubject()->getCommonName();

            if (!$commonName) {
                throw new CertificateStoreException("Certificate doesn't have a common name.");
            }

            if (!isValidName($commonName)) {
                throw new CertificateStoreException("Invalid common name: '{$commonName}'");
            }

            try {
                $chain = \array_slice($certificates, 1);
                $path = $this->root . '/' . $commonName;

                yield File\createDirectoryRecursively($path, 0755);

                yield File\write($path . '/cert.pem', $certificates[0]);
                yield File\changePermissions($path . '/cert.pem', 0644);

                yield File\write($path . '/fullchain.pem', \implode("\n", $certificates));
                yield File\changePermissions($path . '/fullchain.pem', 0644);

                yield File\write($path . '/chain.pem', \implode("\n", $chain));
                yield File\changePermissions($path . '/chain.pem', 0644);
            } catch (FilesystemException $e) {
                throw new CertificateStoreException("Couldn't save certificates for '{$commonName}'", 0, $e);
            }
        });
    }

    public function delete(string $name): Promise
    {
        return call(function () use ($name) {
            /** @var array $files */
            $files = yield File\listFiles($this->root . '/' . $name);

            foreach ($files as $file) {
                yield File\deleteFile($this->root . '/' . $name . '/' . $file);
            }

            yield File\deleteDirectory($this->root . '/' . $name);
        });
    }
}
