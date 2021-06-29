<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\Dns;
use Amp\Dns\NoRecordException;
use Amp\Dns\Record;
use Amp\Promise;
use Kelunik\Acme\AcmeException;
use Kelunik\Acme\Crypto\RsaKeyGenerator;
use Kelunik\Acme\Domain\Registration;
use Kelunik\AcmeClient;
use Kelunik\AcmeClient\AcmeFactory;
use Kelunik\AcmeClient\Stores\KeyStore;
use Kelunik\AcmeClient\Stores\KeyStoreException;
use League\CLImate\Argument\Manager;
use League\CLImate\CLImate;
use Symfony\Component\Yaml\Yaml;
use function Amp\call;
use function Kelunik\AcmeClient\normalizePath;
use function Kelunik\AcmeClient\resolveServer;
use function Kelunik\AcmeClient\serverToKeyname;

class Setup implements Command
{
    public static function getDefinition(): array
    {
        $args = [
            'server' => AcmeClient\getArgumentDescription('server'),
            'storage' => AcmeClient\getArgumentDescription('storage'),
            'email' => [
                'longPrefix' => 'email',
                'description' => 'E-mail for important issues, will be sent to the ACME server.',
                'required' => true,
            ],
        ];

        $configPath = AcmeClient\getConfigPath();

        if ($configPath) {
            $config = Yaml::parse(\file_get_contents($configPath));

            if (isset($config['email']) && \is_string($config['email'])) {
                $args['email']['required'] = false;
                $args['email']['defaultValue'] = $config['email'];
            }
        }

        return $args;
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
            $email = $args->get('email');
            yield from $this->checkEmail($email);

            $server = resolveServer($args->get('server'));
            $keyFile = serverToKeyname($server);

            $path = "accounts/{$keyFile}.pem";
            $bits = 4096;

            $keyStore = new KeyStore(normalizePath($args->get('storage')));

            $this->climate->br();

            try {
                $keyPair = yield $keyStore->get($path);
                $this->climate->whisper('    Using existing private key ...');
            } catch (KeyStoreException $e) {
                $this->climate->whisper('    No private key found, generating new one ...');

                $keyPair = (new RsaKeyGenerator($bits))->generateKey();
                $keyPair = yield $keyStore->put($path, $keyPair);

                $this->climate->whisper("    Generated new private key with {$bits} bits.");
            }

            $acme = $this->acmeFactory->build($server, $keyPair);

            $this->climate->whisper('    Registering with ' . \substr($server, 8) . ' ...');

            /** @var Registration $registration */
            $registration = yield $acme->register($email);
            $this->climate->info('    Registration successful. Contacts: ' . \implode(
                ', ',
                $registration->getContact()
            ));
            $this->climate->br();

            return 0;
        });
    }

    private function checkEmail(string $email)
    {
        $host = \substr($email, \strrpos($email, '@') + 1);

        if (!$host) {
            throw new AcmeException("Invalid contact email: '{$email}'");
        }

        try {
            yield Dns\query($host, Record::MX);
        } catch (NoRecordException $e) {
            throw new AcmeException("No MX record defined for '{$host}'");
        } catch (Dns\DnsException $e) {
            throw new AcmeException(
                "Dns query for an MX record on '{$host}' failed for the following reason: " . $e->getMessage(),
                null,
                $e
            );
        }
    }
}
