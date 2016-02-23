<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\Dns\Record;
use Amp\Dns\ResolutionException;
use Amp\Promise;
use Generator;
use Kelunik\Acme\AcmeClient;
use Kelunik\Acme\AcmeException;
use Kelunik\Acme\AcmeService;
use Kelunik\Acme\KeyPair;
use Kelunik\Acme\OpenSSLKeyGenerator;
use Kelunik\Acme\Registration;
use League\CLImate\Argument\Manager;
use Psr\Log\LoggerInterface;
use function Amp\File\exists;
use function Amp\resolve;

class Register implements Command {
    private $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    public function execute(Manager $args): Promise {
        return resolve($this->doExecute($args));
    }

    public function doExecute(Manager $args): Generator {
        if (posix_geteuid() !== 0) {
            throw new AcmeException("Please run this script as root!");
        }

        $email = $args->get("email");
        yield resolve($this->checkEmail($email));

        $server = $args->get("server");
        $protocol = substr($server, 0, strpos("://", $server));

        if (!$protocol || $protocol === $server) {
            $server = "https://" . $server;
        } elseif ($protocol !== "https") {
            throw new \InvalidArgumentException("Invalid server protocol, only HTTPS supported");
        }

        $identity = str_replace(["/", "%"], "-", substr($server, 8));

        $path = __DIR__ . "/../../data/accounts";
        $pathPrivate = "{$path}/{$identity}.private.key";
        $pathPublic = "{$path}/{$identity}.public.key";

        if ((yield exists($pathPrivate)) && (yield exists($pathPublic))) {
            $this->logger->info("Loading existing keys ...");

            $private = file_get_contents($pathPrivate);
            $public = file_get_contents($pathPublic);

            $keyPair = new KeyPair($private, $public);
        } else {
            $this->logger->info("Generating key keys ...");

            $keyPair = (new OpenSSLKeyGenerator)->generate(4096);

            if (!mkdir($path, 0700, true)) {
                throw new AcmeException("Couldn't create account directory");
            }

            file_put_contents($pathPrivate, $keyPair->getPrivate());
            file_put_contents($pathPublic, $keyPair->getPublic());

            chmod($pathPrivate, 0600);
        }

        $acme = new AcmeService(new AcmeClient($server, $keyPair), $keyPair);

        $this->logger->info("Registering with ACME server " . substr($server, 8) . " ...");

        /** @var Registration $registration */
        $registration = yield $acme->register($email);

        $this->logger->notice("Registration successful with contact " . json_encode($registration->getContact()));
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
                "description" => "ACME server to register for.",
                "required" => true,
            ],
            "email" => [
                "longPrefix" => "email",
                "description" => "Email to be notified about important account issues.",
                "required" => true,
            ],
        ];
    }
}