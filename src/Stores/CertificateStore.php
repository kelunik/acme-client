<?php

namespace Kelunik\AcmeClient\Stores;

use Amp\File;
use Amp\File\FilesystemException;
use Amp\Promise;
use Kelunik\Certificate\Certificate;
use function Amp\call;
use function Amp\Uri\isValidDnsName;

class CertificateStore {
    private $root;

    public function __construct(string $root) {
        $this->root = \rtrim(\str_replace("\\", '/', $root), '/');
    }

    public function get(string $name): Promise {
        return call(function () use ($name) {
            try {
                return yield File\get($this->root . '/' . $name . '/cert.pem');
            } catch (FilesystemException $e) {
                throw new CertificateStoreException('Failed to load certificate.', 0, $e);
            }
        });
    }

    public function put(array $certificates): Promise {
        return call(function () use ($certificates) {
            if (empty($certificates)) {
                throw new \Error('Empty array not allowed');
            }

            $cert = new Certificate($certificates[0]);
            $commonName = $cert->getSubject()->getCommonName();

            if (!$commonName) {
                throw new CertificateStoreException("Certificate doesn't have a common name.");
            }

            if (!isValidDnsName($commonName)) {
                throw new CertificateStoreException("Invalid common name: '{$commonName}'");
            }

            try {
                $chain = \array_slice($certificates, 1);
                $path = $this->root . '/' . $commonName;

                if (!yield File\isdir($path)) {
                    yield File\mkdir($path, 0755, true);

                    if (!yield File\isdir($path)) {
                        throw new FilesystemException("Couldn't create certificate directory: '{$path}'");
                    }
                }

                yield File\put($path . '/cert.pem', $certificates[0]);
                yield File\chmod($path . '/cert.pem', 0644);

                yield File\put($path . '/fullchain.pem', \implode("\n", $certificates));
                yield File\chmod($path . '/fullchain.pem', 0644);

                yield File\put($path . '/chain.pem', \implode("\n", $chain));
                yield File\chmod($path . '/chain.pem', 0644);
            } catch (FilesystemException $e) {
                throw new CertificateStoreException("Couldn't save certificates for '{$commonName}'", 0, $e);
            }
        });
    }

    public function delete(string $name): Promise {
        return call(function () use ($name) {
            /** @var array $files */
            $files = yield File\scandir($this->root . '/' . $name);

            foreach ($files as $file) {
                yield File\unlink($this->root . '/' . $name . '/' . $file);
            }

            yield File\rmdir($this->root . '/' . $name);
        });
    }
}
