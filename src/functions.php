<?php

namespace Kelunik\AcmeClient;

use Amp\Sync\LocalSemaphore;
use Amp\Sync\Lock;
use InvalidArgumentException;
use Kelunik\Acme\AcmeException;
use Phar;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use function Amp\call;
use function Amp\coroutine;

function concurrentMap(int $concurrency, array $values, callable $functor): array
{
    $semaphore = new LocalSemaphore($concurrency);

    return \array_map(coroutine(function ($value, $key) use ($semaphore, $functor) {
        /** @var Lock $lock */
        $lock = yield $semaphore->acquire();

        try {
            return yield call($functor, $value, $key);
        } finally {
            $lock->release();
        }
    }), $values, \array_keys($values));
}

/**
 * Suggests a command based on similarity in a list of available commands.
 *
 * @param string $badCommand invalid command
 * @param array  $commands list of available commands
 * @param int    $suggestThreshold similarity threshold
 *
 * @return string suggestion or empty string if no command is similar enough
 */
function suggestCommand(string $badCommand, array $commands, int $suggestThreshold = 70): string
{
    $badCommand = \strtolower($badCommand);

    $bestMatch = '';
    $bestMatchPercentage = 0;
    $byRefPercentage = 0;

    foreach ($commands as $command) {
        \similar_text($badCommand, \strtolower($command), $byRefPercentage);

        if ($byRefPercentage > $bestMatchPercentage) {
            $bestMatchPercentage = $byRefPercentage;
            $bestMatch = $command;
        }
    }

    return $bestMatchPercentage >= $suggestThreshold ? $bestMatch : '';
}

/**
 * Resolves a server to a valid URI. If a valid shortcut is passed, it's resolved to the defined URI. If a URI without
 * protocol is passed, it will default to HTTPS.
 *
 * @param string $uri URI to resolve
 *
 * @return string resolved URI
 */
function resolveServer(string $uri): string
{
    $shortcuts = [
        'letsencrypt' => 'https://acme-v02.api.letsencrypt.org/directory',
        'letsencrypt:production' => 'https://acme-v02.api.letsencrypt.org/directory',
        'letsencrypt:staging' => 'https://acme-staging-v02.api.letsencrypt.org/directory',
    ];

    if (isset($shortcuts[$uri])) {
        return $shortcuts[$uri];
    }

    if (\strpos($uri, '/') === false) {
        throw new InvalidArgumentException('Invalid server URI: ' . $uri);
    }

    $protocol = \substr($uri, 0, \strpos($uri, '://'));

    if (!$protocol || $protocol === $uri) {
        return "https://{$uri}";
    }

    return $uri;
}

/**
 * Transforms a directory URI to a valid filename for usage as key file name.
 *
 * @param string $server URI to the directory
 *
 * @return string identifier usable as file name
 */
function serverToKeyname(string $server): string
{
    $server = \substr($server, \strpos($server, '://') + 3);

    $keyFile = \str_replace('/', '.', $server);
    $keyFile = \preg_replace('@[^a-z0-9._-]@', '', $keyFile);
    $keyFile = \preg_replace("@\\.+@", '.', $keyFile);

    return $keyFile;
}

/**
 * Checks whether the application is currently running as Phar.
 *
 * @return bool {@code true} if running as Phar, {@code false} otherwise
 */
function isPhar(): bool
{
    if (!\class_exists('Phar')) {
        return false;
    }

    return Phar::running() !== '';
}

/**
 * Normalizes a path. Replaces all backslashes with slashes and removes trailing slashes.
 *
 * @param string $path path to normalize
 *
 * @return string normalized path
 */
function normalizePath(string $path): string
{
    return \rtrim(\str_replace("\\", '/', $path), '/');
}

/**
 * Gets the most appropriate config path to use.
 *
 * @return string|null Resolves to the config path or null.
 */
function getConfigPath(): ?string
{
    $paths = isPhar() ? [\substr(\dirname(Phar::running()), \strlen('phar://')) . '/acme-client.yml'] : [];

    if (0 !== \stripos(PHP_OS, 'WIN')) {
        if ($home = \getenv('HOME')) {
            $paths[] = $home . '/.acme-client.yml';
        }

        $paths[] = '/etc/acme-client.yml';
    }

    do {
        $path = \array_shift($paths);

        if (\file_exists($path)) {
            return $path;
        }
    } while (\count($paths));

    return null;
}

/**
 * Returns a consistent argument description for CLIMate. Valid arguments are "server" and "storage".
 *
 * @param string $argument argument name
 *
 * @return array CLIMate argument description
 * @throws AcmeException if the provided acme-client.yml file is invalid
 * @throws ConfigException if the provided configuration file is invalid
 */
function getArgumentDescription(string $argument): array
{
    $config = [];

    if ($configPath = getConfigPath()) {
        $configContent = \file_get_contents($configPath);

        try {
            $config = Yaml::parse($configContent);

            if (isset($config['server']) && !\is_string($config['server'])) {
                throw new ConfigException("'server' set, but not a string.");
            }

            if (isset($config['storage']) && !\is_string($config['storage'])) {
                throw new ConfigException("'storage' set, but not a string.");
            }
        } catch (ParseException $e) {
            throw new AcmeException("Unable to parse the configuration ({$configPath}): " . $e->getMessage());
        }
    }

    switch ($argument) {
        case 'server':
            $desc = [
                'prefix' => 's',
                'longPrefix' => 'server',
                'description' => 'ACME server to use for registration and issuance of certificates.',
                'required' => true,
            ];

            if (isset($config['server'])) {
                $desc['required'] = false;
                $desc['defaultValue'] = $config['server'];
            }

            return $desc;

        case 'storage':
            $isPhar = isPhar();

            $desc = [
                'longPrefix' => 'storage',
                'description' => 'Storage directory for account keys and certificates.',
                'required' => $isPhar,
            ];

            if (!$isPhar) {
                $desc['defaultValue'] = \dirname(__DIR__) . '/data';
            } elseif (isset($config['storage'])) {
                $desc['required'] = false;
                $desc['defaultValue'] = $config['storage'];
            }

            return $desc;

        default:
            throw new InvalidArgumentException('Unknown argument: ' . $argument);
    }
}

/**
 * Returns the binary that currently runs. Can be included in help texts about other commands.
 *
 * @return string binary callable, shortened based on PATH and CWD
 */
function getBinary(): string
{
    $binary = 'bin/acme';

    if (isPhar()) {
        $binary = \substr(Phar::running(), \strlen('phar://'));

        $path = \getenv('PATH');
        $locations = \explode(PATH_SEPARATOR, $path);

        $binaryPath = \dirname($binary);

        foreach ($locations as $location) {
            if ($location === $binaryPath) {
                return \substr($binary, \strlen($binaryPath) + 1);
            }
        }

        $cwd = \getcwd();

        if ($cwd && \strpos($binary, $cwd) === 0) {
            $binary = '.' . \substr($binary, \strlen($cwd));
        }
    }

    return $binary;
}

/**
 * Cuts a text to a certain length and appends an ellipsis if necessary.
 *
 * @param string $text text to shorten
 * @param int    $max maximum length
 * @param string $append appendix when too long
 *
 * @return string shortened string
 */
function ellipsis(string $text, int $max = 70, string $append = '…'): string
{
    if (\strlen($text) <= $max) {
        return $text;
    }

    $out = \substr($text, 0, $max);

    if (\strpos($text, ' ') === false) {
        return $out . $append;
    }

    return \preg_replace("/\\w+$/", '', $out) . $append;
}
