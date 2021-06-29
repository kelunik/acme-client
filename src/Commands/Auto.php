<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\File;
use Amp\File\FilesystemException;
use Amp\Process\Process;
use Amp\Promise;
use Kelunik\Acme\AcmeException;
use Kelunik\AcmeClient;
use Kelunik\AcmeClient\ConfigException;
use League\CLImate\Argument\Manager;
use League\CLImate\CLImate;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use function Amp\ByteStream\buffer;
use function Amp\call;

class Auto implements Command
{
    private const EXIT_CONFIG_ERROR = 1;
    private const EXIT_SETUP_ERROR = 2;
    private const EXIT_ISSUANCE_ERROR = 3;
    private const EXIT_ISSUANCE_PARTIAL = 4;
    private const EXIT_ISSUANCE_OK = 5;

    private const STATUS_NO_CHANGE = 0;
    private const STATUS_RENEWED = 1;

    public static function getDefinition(): array
    {
        $server = AcmeClient\getArgumentDescription('server');
        $storage = AcmeClient\getArgumentDescription('storage');

        $server['required'] = false;
        $storage['required'] = false;

        $args = [
            'server' => $server,
            'storage' => $storage,
            'config' => [
                'prefix' => 'c',
                'longPrefix' => 'config',
                'description' => 'Configuration file to read.',
                'required' => true,
            ],
        ];

        $configPath = AcmeClient\getConfigPath();

        if ($configPath) {
            $args['config']['required'] = false;
            $args['config']['defaultValue'] = $configPath;
        }

        return $args;
    }

    private $climate;

    public function __construct(CLImate $climate)
    {
        $this->climate = $climate;
    }

    public function execute(Manager $args): Promise
    {
        return call(function () use ($args) {
            $configPath = $args->get('config');

            try {
                /** @var array $config */
                $config = Yaml::parse(
                    yield File\read($configPath)
                );
            } catch (FilesystemException $e) {
                $this->climate->error("Config file ({$configPath}) not found.");
                return self::EXIT_CONFIG_ERROR;
            } catch (ParseException $e) {
                $this->climate->error("Config file ({$configPath}) had an invalid format and couldn't be parsed.");
                return self::EXIT_CONFIG_ERROR;
            }

            if ($args->defined('server')) {
                $config['server'] = $args->get('server');
            } elseif (!isset($config['server']) && $args->exists('server')) {
                $config['server'] = $args->get('server');
            }

            if ($args->defined('storage')) {
                $config['storage'] = $args->get('storage');
            } elseif (!isset($config['storage']) && $args->exists('storage')) {
                $config['storage'] = $args->get('storage');
            }

            if (!isset($config['server'])) {
                $this->climate->error("Config file ({$configPath}) didn't have a 'server' set nor was it passed as command line argument.");
                return self::EXIT_CONFIG_ERROR;
            }

            if (!isset($config['storage'])) {
                $this->climate->error("Config file ({$configPath}) didn't have a 'storage' set nor was it passed as command line argument.");
                return self::EXIT_CONFIG_ERROR;
            }

            if (!isset($config['email'])) {
                $this->climate->error("Config file ({$configPath}) didn't have a 'email' set.");
                return self::EXIT_CONFIG_ERROR;
            }

            if (!isset($config['certificates']) || !\is_array($config['certificates'])) {
                $this->climate->error("Config file ({$configPath}) didn't have a 'certificates' section that's an array.");
                return self::EXIT_CONFIG_ERROR;
            }

            if (isset($config['challenge-concurrency']) && !\is_numeric($config['challenge-concurrency'])) {
                $this->climate->error("Config file ({$configPath}) defines an invalid 'challenge-concurrency' value.");
                return self::EXIT_CONFIG_ERROR;
            }

            foreach ($config['certificates'] as $certificateConfig) {
                if (isset($certificateConfig['rekey']) && !\is_bool($certificateConfig['rekey'])) {
                    $this->climate->error("Config file ({$configPath}) defines an invalid 'rekey' value.");
                    return self::EXIT_CONFIG_ERROR;
                }
            }

            $concurrency = isset($config['challenge-concurrency']) ? (int) $config['challenge-concurrency'] : null;

            $process = new Process([
                PHP_BINARY,
                $GLOBALS['argv'][0],
                'setup',
                '--server',
                $config['server'],
                '--storage',
                $config['storage'],
                '--email',
                $config['email'],
            ]);

            $process->start();
            $exit = yield $process->join();

            if ($exit !== 0) {
                $this->climate->error("Registration failed ({$exit})");
                $this->climate->br()->out(yield buffer($process->getStdout()));
                $this->climate->br()->error(yield buffer($process->getStderr()));

                return self::EXIT_SETUP_ERROR;
            }

            $errors = [];
            $values = [];

            foreach ($config['certificates'] as $i => $certificate) {
                try {
                    $exit = yield call(function () use ($certificate, $config, $concurrency) {
                        return $this->checkAndIssue($certificate, $config['server'], $config['storage'], $concurrency);
                    });

                    $values[$i] = $exit;
                } catch (\Exception $e) {
                    $errors[$i] = $e;
                }
            }

            $status = [
                'no_change' => \count(\array_filter($values, function ($value) {
                    return $value === self::STATUS_NO_CHANGE;
                })),
                'renewed' => \count(\array_filter($values, function ($value) {
                    return $value === self::STATUS_RENEWED;
                })),
                'failure' => \count($errors),
            ];

            if ($status['renewed'] > 0) {
                foreach ($values as $i => $value) {
                    if ($value === self::STATUS_RENEWED) {
                        $certificate = $config['certificates'][$i];
                        $this->climate->info('Certificate for ' . \implode(
                            ', ',
                            \array_keys($this->toDomainPathMap($certificate['paths']))
                        ) . ' successfully renewed.');
                    }
                }
            }

            if ($status['failure'] > 0) {
                foreach ($errors as $i => $error) {
                    $certificate = $config['certificates'][$i];
                    $this->climate->error('Issuance for the following domains failed: ' . \implode(
                        ', ',
                        \array_keys($this->toDomainPathMap($certificate['paths']))
                    ));
                    $this->climate->error("Reason: {$error}");
                }

                $exitCode = $status['renewed'] > 0
                    ? self::EXIT_ISSUANCE_PARTIAL
                    : self::EXIT_ISSUANCE_ERROR;

                return $exitCode;
            }

            if ($status['renewed'] > 0) {
                return self::EXIT_ISSUANCE_OK;
            }
        });
    }

    /**
     * @param array    $certificate certificate configuration
     * @param string   $server server to use for issuance
     * @param string   $storage storage directory
     * @param int|null $concurrency concurrent challenges
     *
     * @return \Generator
     * @throws AcmeException if something does wrong
     * @throws \Throwable
     */
    private function checkAndIssue(
        array $certificate,
        string $server,
        string $storage,
        int $concurrency = null
    ): \Generator {
        $domainPathMap = $this->toDomainPathMap($certificate['paths']);
        $domains = \array_keys($domainPathMap);
        $commonName = \reset($domains);
        $processArgs = [
            PHP_BINARY,
            $GLOBALS['argv'][0],
            'check',
            '--server',
            $server,
            '--storage',
            $storage,
            '--name',
            $commonName,
            '--names',
            \implode(',', $domains),
        ];

        if ($certificate['rekey'] ?? false) {
            $processArgs[] = '--rekey';
        }

        $process = new Process($processArgs);

        $process->start();
        $exit = yield $process->join();

        if ($exit === 0) {
            // No need for renewal
            return self::STATUS_NO_CHANGE;
        }

        if ($exit === 1) {
            // Renew certificate
            $args = [
                PHP_BINARY,
                $GLOBALS['argv'][0],
                'issue',
                '--server',
                $server,
                '--storage',
                $storage,
                '--domains',
                \implode(',', $domains),
                '--path',
                \implode(PATH_SEPARATOR, \array_values($domainPathMap)),
            ];

            if (isset($certificate['user'])) {
                $args[] = '--user';
                $args[] = $certificate['user'];
            }

            if (isset($certificate['bits'])) {
                $args[] = '--bits';
                $args[] = $certificate['bits'];
            }

            if ($concurrency) {
                $args[] = '--challenge-concurrency';
                $args[] = $concurrency;
            }

            $process = new Process($args);
            $process->start();
            $exit = yield $process->join();

            if ($exit !== 0) {
                // TODO: Print STDOUT and STDERR to file
                throw new AcmeException("Unexpected exit code ({$exit}) for '{$process->getCommand()}'.");
            }

            return self::STATUS_RENEWED;
        }

        // TODO: Print STDOUT and STDERR to file
        throw new AcmeException("Unexpected exit code ({$exit}) for '{$process->getCommand()}'.");
    }

    private function toDomainPathMap(array $paths)
    {
        $result = [];

        foreach ($paths as $path => $domains) {
            if (\is_numeric($path)) {
                $message = <<<MESSAGE
Your configuration has the wrong format. Received a numeric value as path name.

This is most probably due to your "paths" value not being a map but a list instead.

If your configuration looks like this:

certificates:
 - paths:
    - /www/a: a.example.org
    - /www/b: b.example.org

Rewrite it to the following format for a single certificate:

certificates:
 - paths:
     /www/a: a.example.org
     /www/b: b.example.org

Rewrite it to the following format for two separate certificates:

certificates:
 - paths:
     /www/a: a.example.org
 - paths:
     /www/b: b.example.org

Documentation is available at https://github.com/kelunik/acme-client/blob/master/doc/usage.md#configuration

If this doesn't solve your issue, please reply to the following issue: https://github.com/kelunik/acme-client/issues/30
MESSAGE;

                throw new ConfigException($message);
            }

            $domains = (array) $domains;

            foreach ($domains as $domain) {
                if (isset($result[$domain])) {
                    throw new ConfigException("Duplicate domain: {$domain}");
                }

                $result[$domain] = $path;
            }
        }

        return $result;
    }
}
