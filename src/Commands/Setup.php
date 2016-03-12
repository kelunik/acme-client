<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\Dns\Record;
use Amp\Dns\ResolutionException;
use Amp\Promise;
use Generator;
use InvalidArgumentException;
use Kelunik\Acme\AcmeClient;
use Kelunik\Acme\AcmeException;
use Kelunik\Acme\AcmeService;
use Kelunik\Acme\OpenSSLKeyGenerator;
use Kelunik\Acme\Registration;
use Kelunik\AcmeClient\Stores\KeyStore;
use Kelunik\AcmeClient\Stores\KeyStoreException;
use League\CLImate\Argument\Manager;
use Psr\Log\LoggerInterface;
use RuntimeException;

class Setup implements Command {
    private $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    public function execute(Manager $args) {
        return \Amp\resolve($this->doExecute($args));
    }

    public function doExecute(Manager $args) {
        $email = $args->get("email");
        yield \Amp\resolve($this->checkEmail($email));

        $server = $args->get("server");
        $keyFile = \Kelunik\AcmeClient\serverToKeyname($server);

        $path = "accounts/{$keyFile}.pem";
        $bits = 4096;

        $keyStore = new KeyStore(dirname(dirname(__DIR__)) . "/data");

        try {
            $this->logger->info("Loading private key ...");
            $keyPair = (yield $keyStore->get($path));
            $this->logger->info("Existing private key successfully loaded.");
        } catch (KeyStoreException $e) {
            $this->logger->info("No existing private key found, generating new one ...");
            $keyPair = (new OpenSSLKeyGenerator)->generate($bits);
            $this->logger->info("Generated new private key with {$bits} bits.");

            $this->logger->info("Saving new private key ...");
            $keyPair = (yield $keyStore->put($path, $keyPair));
            $this->logger->info("New private key successfully saved.");
        }

        $acme = new AcmeService(new AcmeClient($server, $keyPair), $keyPair);

        $this->logger->info("Registering with ACME server " . substr($server, 8) . " ...");
        /** @var Registration $registration */
        $registration = (yield $acme->register($email));
        $this->logger->notice("Registration successful with the following contact information: " . implode(", ", $registration->getContact()));

        yield \Amp\File\put(dirname(dirname(__DIR__)) . "/data/account/config.json", json_encode([
            "version" => 1,
            "server" => $server,
            "email" => $email,
        ], JSON_PRETTY_PRINT) . "\n");
    }

    private function checkEmail($email) {
        if (!is_string($email)) {
            throw new InvalidArgumentException(sprintf("\$email must be of type string, %s given.", gettype($email)));
        }

        $host = substr($email, strrpos($email, "@") + 1);

        if (!$host) {
            throw new AcmeException("Invalid contact email: '{$email}'");
        }

        try {
            yield \Amp\Dns\query($host, Record::MX);
        } catch (ResolutionException $e) {
            throw new AcmeException("No MX record defined for '{$host}'");
        }
    }

    public static function getDefinition() {
        return [
            "server" => [
                "prefix" => "s",
                "longPrefix" => "server",
                "description" => "ACME server to use for registration and issuance of certificates.",
                "required" => true,
            ],
            "email" => [
                "longPrefix" => "email",
                "description" => "Email for important issues, will be sent to the ACME server.",
                "required" => true,
            ],
        ];
    }
}