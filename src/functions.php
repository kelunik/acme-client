<?php

namespace Kelunik\AcmeClient;

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
    $keyFile = str_replace("/", ".", $server);
    $keyFile = preg_replace("[^a-z0-9._-]", "", $keyFile);
    $keyFile = preg_replace("\\.+", ".", $keyFile);

    return $keyFile;
}