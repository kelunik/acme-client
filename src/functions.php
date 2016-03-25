<?php

namespace Kelunik\AcmeClient;

use Kelunik\Acme\AcmeException;
use Phar;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Webmozart\Assert\Assert;

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

    $protocol = substr($uri, 0, strpos($uri, "://"));

    if (!$protocol || $protocol === $uri) {
        return "https://{$uri}";
    } else {
        return $uri;
    }
}

function serverToKeyname($server) {
    $server = substr($server, strpos($server, "://") + 3);

    $keyFile = str_replace("/", ".", $server);
    $keyFile = preg_replace("@[^a-z0-9._-]@", "", $keyFile);
    $keyFile = preg_replace("@\\.+@", ".", $keyFile);

    return $keyFile;
}

function isPhar() {
    if (!class_exists("Phar")) {
        return false;
    }

    return Phar::running(true) !== "";
}

function normalizePath($path) {
    return rtrim(str_replace("\\", "/", $path), "/");
}

function getArgumentDescription($argument) {
    $isPhar = \Kelunik\AcmeClient\isPhar();

    $config = [];

    if ($isPhar) {
        $configPath = substr(dirname(Phar::running(true)), strlen("phar://")) . "/acme-client.yml";

        if (file_exists($configPath)) {
            $configContent = file_get_contents($configPath);

            try {
                $value = Yaml::parse($configContent);

                if (isset($value["server"]) && is_string($value["server"])) {
                    $config["server"] = $value["server"];
                    unset($value["server"]);
                }

                if (isset($value["storage"]) && is_string($value["storage"])) {
                    $config["storage"] = $value["storage"];
                    unset($value["storage"]);
                }

                if (!empty($value)) {
                    throw new AcmeException("Provided YAML file had unknown options: " . implode(", ", array_keys($value)));
                }
            } catch (ParseException $e) {
                throw new AcmeException("Unable to parse the YAML file ({$configPath}): " . $e->getMessage());
            }
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
            throw new \InvalidArgumentException("Unknown argument: " . $argument);
    }
}

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