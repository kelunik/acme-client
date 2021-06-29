<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\File;
use Amp\File\FilesystemException;
use Amp\Promise;
use Kelunik\AcmeClient;
use Kelunik\AcmeClient\AcmeFactory;
use Kelunik\AcmeClient\Stores\CertificateStore;
use Kelunik\AcmeClient\Stores\KeyStore;
use Kelunik\Certificate\Certificate;
use League\CLImate\Argument\Manager;
use League\CLImate\CLImate;
use function Amp\call;

class Revoke implements Command
{
    public static function getDefinition(): array
    {
        return [
            'server' => AcmeClient\getArgumentDescription('server'),
            'storage' => AcmeClient\getArgumentDescription('storage'),
            'name' => [
                'longPrefix' => 'name',
                'description' => 'Common name of the certificate to be revoked.',
                'required' => true,
            ],
        ];
    }

    private $climate;
    private $acmeFactory;

    public function __construct(CLImate $climate, AcmeFactory $acmeFactory)
    {
        $this->climate = $climate;
        $this->acmeFactory = $acmeFactory;
    }

    public function execute(Manager $args): Promise
    {
        return call(function () use ($args) {
            $keyStore = new KeyStore(AcmeClient\normalizePath($args->get('storage')));

            $server = AcmeClient\resolveServer($args->get('server'));
            $keyFile = AcmeClient\serverToKeyname($server);

            $keyPair = yield $keyStore->get("accounts/{$keyFile}.pem");
            $acme = $this->acmeFactory->build($server, $keyPair);

            $this->climate->br();
            $this->climate->whisper('    Revoking certificate ...');

            $path = AcmeClient\normalizePath($args->get('storage')) . '/certs/' . $keyFile . '/' . $args->get('name') . '/cert.pem';

            try {
                $pem = yield File\read($path);
                $cert = new Certificate($pem);
            } catch (FilesystemException $e) {
                throw new \RuntimeException("There's no such certificate (" . $path . ')');
            }

            if ($cert->getValidTo() < \time()) {
                $this->climate->comment('    Certificate did already expire, no need to revoke it.');
            }

            $names = $cert->getNames();
            $this->climate->whisper('    Certificate was valid for ' . \count($names) . ' domains.');
            $this->climate->whisper('     - ' . \implode(PHP_EOL . '     - ', $names) . PHP_EOL);

            yield $acme->revokeCertificate($pem);

            $this->climate->br();
            $this->climate->info('    Certificate has been revoked.');

            yield (new CertificateStore(AcmeClient\normalizePath($args->get('storage')) . '/certs/' . $keyFile))->delete($args->get('name'));

            return 0;
        });
    }
}
