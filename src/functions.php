<?php

namespace Kelunik\AcmeClient;

use InvalidArgumentException;
use Kelunik\Acme\AcmeException;
use Phar;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Webmozart\Assert\Assert;

/**
 * Suggests a command based on similarity in a list of available commands.
 *
 * @param string $badCommand invalid command
 * @param array  $commands list of available commands
 * @param int    $suggestThreshold similarity threshold
 * @return string suggestion or empty string if no command is similar enough
 */
function suggestCommand($badCommand, array $commands, $suggestThreshold = 70) {
    Assert::string($badCommand, "Bad command must be a string. Got: %s");
    Assert::integer($suggestThreshold, "Suggest threshold must be an integer. Got: %s");

    $badCommand = strtolower($badCommand);

    $bestMatch = "";
    $bestMatchPercentage = 0;
    $byRefPercentage = 0;

    foreach ($commands as $command) {
        \similar_text($badCommand, strtolower($command), $byRefPercentage);

        if ($byRefPercentage > $bestMatchPercentage) {
            $bestMatchPercentage = $byRefPercentage;
            $bestMatch = $command;
        }
    }

    return $bestMatchPercentage >= $suggestThreshold ? $bestMatch : "";
}

/**
 * Resolves a server to a valid URI. If a valid shortcut is passed, it's resolved to the defined URI. If a URI without
 * protocol is passed, it will default to HTTPS.
 *
 * @param string $uri URI to resolve
 * @return string resolved URI
 */
function resolveServer($uri) {
    Assert::string($uri, "URI must be a string. Got: %s");

    $shortcuts = [
        "letsencrypt" => "https://acme-v01.api.letsencrypt.org/directory",
        "letsencrypt:production" => "https://acme-v01.api.letsencrypt.org/directory",
        "letsencrypt:staging" => "https://acme-staging.api.letsencrypt.org/directory",
    ];

    if (isset($shortcuts[$uri])) {
        return $shortcuts[$uri];
    }

    if (strpos($uri, "/") === false) {
        throw new InvalidArgumentException("Invalid server URI: " . $uri);
    }

    $protocol = substr($uri, 0, strpos($uri, "://"));

    if (!$protocol || $protocol === $uri) {
        return "https://{$uri}";
    } else {
        return $uri;
    }
}

/**
 * Transforms a directory URI to a valid filename for usage as key file name.
 *
 * @param string $server URI to the directory
 * @return string identifier usable as file name
 */
function serverToKeyname($server) {
    $server = substr($server, strpos($server, "://") + 3);

    $keyFile = str_replace("/", ".", $server);
    $keyFile = preg_replace("@[^a-z0-9._-]@", "", $keyFile);
    $keyFile = preg_replace("@\\.+@", ".", $keyFile);

    return $keyFile;
}

/**
 * Checks whether the application is currently running as Phar.
 *
 * @return bool {@code true} if running as Phar, {@code false} otherwise
 */
function isPhar() {
    if (!class_exists("Phar")) {
        return false;
    }

    return Phar::running(true) !== "";
}

/**
 * Normalizes a path. Replaces all backslashes with slashes and removes trailing slashes.
 *
 * @param string $path path to normalize
 * @return string normalized path
 */
function normalizePath($path) {
    return rtrim(str_replace("\\", "/", $path), "/");
}

/**
 * Gets the most appropriate config path to use.
 *
 * @return string|null Resolves to the config path or null.
 */
function getConfigPath() {
    $paths = isPhar() ? [substr(dirname(Phar::running(true)), strlen("phar://")) . "acme-client.yml"] : [];

    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        if ($home = getenv("HOME")) {
            $paths[] = $home . "/.acme-client.yml";
        }

        $paths[] = "/etc/acme-client.yml";
    }

    do {
        $path = array_shift($paths);

        if (file_exists($path)) {
            return $path;
        }
    } while (count($paths));

    return null;
}

/**
 * Returns a consistent argument description for CLIMate. Valid arguments are "server" and "storage".
 *
 * @param string $argument argument name
 * @return array CLIMate argument description
 * @throws AcmeException if the provided acme-client.yml file is invalid
 * @throws ConfigException if the provided configuration file is invalid
 */
function getArgumentDescription($argument) {
    $config = [];

    if ($configPath = getConfigPath()) {
        $configContent = file_get_contents($configPath);

        try {
            $config = Yaml::parse($configContent);

            if (isset($config["server"]) && !is_string($config["server"])) {
                throw new ConfigException("'server' set, but not a string.");
            }

            if (isset($config["storage"]) && !is_string($config["storage"])) {
                throw new ConfigException("'storage' set, but not a string.");
            }
        } catch (ParseException $e) {
            throw new AcmeException("Unable to parse the configuration ({$configPath}): " . $e->getMessage());
        }
    }

    switch ($argument) {
        case "server":
            $argument = [
                "prefix" => "s",
                "longPrefix" => "server",
                "description" => "ACME server to use for registration and issuance of certificates.",
                "required" => true,
            ];

            if (isset($config["server"])) {
                $argument["required"] = false;
                $argument["defaultValue"] = $config["server"];
            }

            return $argument;

        case "storage":
            $isPhar = isPhar();

            $argument = [
                "longPrefix" => "storage",
                "description" => "Storage directory for account keys and certificates.",
                "required" => $isPhar,
            ];

            if (!$isPhar) {
                $argument["defaultValue"] = dirname(__DIR__) . "/data";
            } else if (isset($config["storage"])) {
                $argument["required"] = false;
                $argument["defaultValue"] = $config["storage"];
            }

            return $argument;

        default:
            throw new InvalidArgumentException("Unknown argument: " . $argument);
    }
}

/**
 * Returns the binary that currently runs. Can be included in help texts about other commands.
 *
 * @return string binary callable, shortened based on PATH and CWD
 */
function getBinary() {
    $binary = "bin/acme";

    if (isPhar()) {
        $binary = substr(Phar::running(true), strlen("phar://"));

        $path = getenv("PATH");
        $locations = explode(PATH_SEPARATOR, $path);

        $binaryPath = dirname($binary);

        foreach ($locations as $location) {
            if ($location === $binaryPath) {
                return substr($binary, strlen($binaryPath) + 1);
            }
        }

        $cwd = getcwd();

        if ($cwd && strpos($binary, $cwd) === 0) {
            $binary = "." . substr($binary, strlen($cwd));
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
 * @return string shortened string
 */
function ellipsis($text, $max = 70, $append = "…") {
    if (strlen($text) <= $max) {
        return $text;
    }

    $out = substr($text, 0, $max);

    if (strpos($text, " ") === false) {
        return $out . $append;
    }

    return preg_replace("/\\w+$/", "", $out) . $append;
}