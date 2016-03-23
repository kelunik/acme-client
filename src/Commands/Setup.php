<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\CoroutineResult;
use Amp\Dns\Record;
use Amp\Dns\ResolutionException;
use InvalidArgumentException;
use Kelunik\Acme\AcmeClient;
use Kelunik\Acme\AcmeException;
use Kelunik\Acme\AcmeService;
use Kelunik\Acme\OpenSSLKeyGenerator;
use Kelunik\Acme\Registration;
use Kelunik\AcmeClient\Stores\KeyStore;
use Kelunik\AcmeClient\Stores\KeyStoreException;
use League\CLImate\Argument\Manager;
use League\CLImate\CLImate;

class Setup implements Command {
    private $climate;

    public function __construct(CLImate $climate) {
        $this->climate = $climate;
    }

    public function execute(Manager $args) {
        return \Amp\resolve($this->doExecute($args));
    }

    public function doExecute(Manager $args) {
        $email = $args->get("email");
        yield \Amp\resolve($this->checkEmail($email));

        $server = \Kelunik\AcmeClient\resolveServer($args->get("server"));
        $keyFile = \Kelunik\AcmeClient\serverToKeyname($server);

        $path = "accounts/{$keyFile}.pem";
        $bits = 4096;

        $keyStore = new KeyStore(\Kelunik\AcmeClient\normalizePath($args->get("storage")));

        try {
            $keyPair = (yield $keyStore->get($path));
            $this->climate->info("Existing private key successfully loaded.");
        } catch (KeyStoreException $e) {
            $this->climate->info("No private key found, generating new one ...");

            $keyPair = (new OpenSSLKeyGenerator)->generate($bits);
            $keyPair = (yield $keyStore->put($path, $keyPair));

            $this->climate->info("Generated new private key with {$bits} bits.");
        }

        $acme = new AcmeService(new AcmeClient($server, $keyPair), $keyPair);

        $this->climate->info("Registering with ACME server " . substr($server, 8) . " ...");

        /** @var Registration $registration */
        $registration = (yield $acme->register($email));
        $this->climate->whisper("Registration successful with the following contact information: " . implode(", ", $registration->getContact()));

        yield new CoroutineResult(0);
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
            "server" => \Kelunik\AcmeClient\getArgumentDescription("server"),
            "storage" => \Kelunik\AcmeClient\getArgumentDescription("storage"),
            "email" => [
                "longPrefix" => "email",
                "description" => "E-mail for important issues, will be sent to the ACME server.",
                "required" => true,
            ],
        ];
    }
}