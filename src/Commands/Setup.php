<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\Dns\Record;
use Amp\Dns\ResolutionException;
use Amp\Promise;
use Generator;
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
use function Amp\File\exists;
use function Amp\File\put;
use function Amp\resolve;
use function Kelunik\AcmeClient\serverToIdentity;

class Setup implements Command {
    private $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    public function execute(Manager $args): Promise {
        return resolve($this->doExecute($args));
    }

    public function doExecute(Manager $args): Generator {
        $email = $args->get("email");
        yield resolve($this->checkEmail($email));

        $server = $args->get("server");
        $protocol = substr($server, 0, strpos("://", $server));

        if (!$protocol || $protocol === $server) {
            $server = "https://" . $server;
        } elseif ($protocol !== "https") {
            throw new \InvalidArgumentException("Invalid protocol, only https is allowed!");
        }

        $path = "account/key.pem";
        $bits = 4096;

        $keyStore = new KeyStore(dirname(dirname(__DIR__)) . "/data");

        try {
            $this->logger->info("Loading private key ...");
            $keyPair = yield $keyStore->get($path);
            $this->logger->info("Existing private key successfully loaded.");
        } catch (KeyStoreException $e) {
            $this->logger->info("No existing private key found, generating new one ...");
            $keyPair = (new OpenSSLKeyGenerator)->generate($bits);
            $this->logger->info("Generated new private key with {$bits} bits.");

            $this->logger->info("Saving new private key ...");
            $keyPair = yield $keyStore->put($path, $keyPair);
            $this->logger->info("New private key successfully saved.");
        }

        $user = $args->get("user") ?? "www-data";
        $userInfo = posix_getpwnam($user);

        if (!$userInfo) {
            throw new RuntimeException("User doesn't exist: '{$user}'");
        }

        $acme = new AcmeService(new AcmeClient($server, $keyPair), $keyPair);

        $this->logger->info("Registering with ACME server " . substr($server, 8) . " ...");
        /** @var Registration $registration */
        $registration = yield $acme->register($email);
        $this->logger->notice("Registration successful with the following contact information: " . implode(", ", $registration->getContact()));

        yield put(dirname(dirname(__DIR__)) . "/data/account/config.json", json_encode([
            "version" => 1,
            "server" => $server,
            "email" => $email,
        ], JSON_PRETTY_PRINT) . "\n");
    }

    private function checkEmail(string $email): Generator {
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

    public static function getDefinition(): array {
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