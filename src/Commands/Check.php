<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\Promise;
use Kelunik\AcmeClient;
use Kelunik\AcmeClient\Stores\CertificateStore;
use Kelunik\AcmeClient\Stores\CertificateStoreException;
use Kelunik\Certificate\Certificate;
use League\CLImate\Argument\Manager;
use League\CLImate\CLImate;
use function Amp\call;

class Check implements Command {
    private $climate;

    public function __construct(CLImate $climate) {
        $this->climate = $climate;
    }

    public function execute(Manager $args): Promise {
        return call(function () use ($args) {
            $server = AcmeClient\resolveServer($args->get('server'));
            $server = AcmeClient\serverToKeyname($server);

            $path = AcmeClient\normalizePath($args->get('storage')) . '/certs/' . $server;
            $certificateStore = new CertificateStore($path);

            try {
                $pem = yield $certificateStore->get($args->get('name'));
            } catch (CertificateStoreException $e) {
                $this->climate->br()->error('    Certificate not found.')->br();

                return 1;
            }

            $cert = new Certificate($pem);

            $this->climate->br();
            $this->climate->whisper('    Certificate is valid until ' . date('d.m.Y', $cert->getValidTo()))->br();

            if ($args->defined('names')) {
                $names = array_map('trim', explode(',', $args->get('names')));
                $missingNames = array_diff($names, $cert->getNames());

                if ($missingNames) {
                    $this->climate->comment('    The following names are not covered: ' . implode(', ', $missingNames))->br();

                    return 1;
                }
            }

            if ($cert->getValidTo() > time() + $args->get('ttl') * 24 * 60 * 60) {
                return 0;
            }

            $this->climate->comment('    Certificate is going to expire within the specified ' . $args->get('ttl') . ' days.')->br();

            return 1;
        });
    }

    public static function getDefinition(): array {
        return [
            'server' => \Kelunik\AcmeClient\getArgumentDescription('server'),
            'storage' => \Kelunik\AcmeClient\getArgumentDescription('storage'),
            'name' => [
                'longPrefix' => 'name',
                'description' => 'Common name of the certificate to check.',
                'required' => true,
            ],
            'ttl' => [
                'longPrefix' => 'ttl',
                'description' => 'Minimum valid time in days.',
                'defaultValue' => 30,
                'castTo' => 'int',
            ],
            'names' => [
                'longPrefix' => 'names',
                'description' => 'Names that must be covered by the certificate identified based on the common name. Names have to be separated by commas.',
                'required' => false,
            ],
        ];
    }
}