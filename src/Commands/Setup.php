<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\CoroutineResult;
use Amp\Dns\Record;
use Amp\Dns\ResolutionException;
use InvalidArgumentException;
use Kelunik\Acme\AcmeException;
use Kelunik\Acme\OpenSSLKeyGenerator;
use Kelunik\Acme\Registration;
use Kelunik\AcmeClient\AcmeFactory;
use Kelunik\AcmeClient\Stores\KeyStore;
use Kelunik\AcmeClient\Stores\KeyStoreException;
use League\CLImate\Argument\Manager;
use League\CLImate\CLImate;
use Symfony\Component\Yaml\Yaml;

class Setup implements Command {
    private $climate;
    private $acmeFactory;

    public function __construct(CLImate $climate, AcmeFactory $acmeFactory) {
        $this->climate = $climate;
        $this->acmeFactory = $acmeFactory;
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

        $this->climate->br();

        try {
            $keyPair = (yield $keyStore->get($path));
            $this->climate->whisper("    Using existing private key ...");
        } catch (KeyStoreException $e) {
            $this->climate->whisper("    No private key found, generating new one ...");

            $keyPair = (new OpenSSLKeyGenerator)->generate($bits);
            $keyPair = (yield $keyStore->put($path, $keyPair));

            $this->climate->whisper("    Generated new private key with {$bits} bits.");
        }

        $acme = $this->acmeFactory->build($server, $keyPair);

        $this->climate->whisper("    Registering with " . substr($server, 8) . " ...");

        /** @var Registration $registration */
        $registration = (yield $acme->register($email));
        $this->climate->info("    Registration successful. Contacts: " . implode(", ", $registration->getContact()));
        $this->climate->br();

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
        $args = [
            "server" => \Kelunik\AcmeClient\getArgumentDescription("server"),
            "storage" => \Kelunik\AcmeClient\getArgumentDescription("storage"),
            "email" => [
                "longPrefix" => "email",
                "description" => "E-mail for important issues, will be sent to the ACME server.",
                "required" => true,
            ],
        ];

        $configPath = \Kelunik\AcmeClient\getConfigPath();

        if ($configPath) {
            $config = Yaml::parse(file_get_contents($configPath));

            if (isset($config["email"]) && is_string($config["email"])) {
                $args["email"]["required"] = false;
                $args["email"]["defaultValue"] = $config["email"];
            }
        }

        return $args;
    }
}