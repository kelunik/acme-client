<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\CoroutineResult;
use Amp\File\FilesystemException;
use Amp\Process;
use Kelunik\Acme\AcmeException;
use Kelunik\AcmeClient\ConfigException;
use League\CLImate\Argument\Manager;
use League\CLImate\CLImate;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class Auto implements Command {
    const EXIT_CONFIG_ERROR = 1;
    const EXIT_SETUP_ERROR = 2;
    const EXIT_ISSUANCE_ERROR = 3;
    const EXIT_ISSUANCE_PARTIAL = 4;
    const EXIT_ISSUANCE_OK = 5;

    const STATUS_NO_CHANGE = 0;
    const STATUS_RENEWED = 1;

    private $climate;

    public function __construct(CLImate $climate) {
        $this->climate = $climate;
    }

    public function execute(Manager $args) {
        return \Amp\resolve($this->doExecute($args));
    }

    /**
     * @param Manager $args
     * @return \Generator
     */
    private function doExecute(Manager $args) {
        $configPath = $args->get("config");

        try {
            $config = Yaml::parse(
                yield \Amp\File\get($configPath)
            );
        } catch (FilesystemException $e) {
            $this->climate->error("Config file ({$configPath}) not found.");
            yield new CoroutineResult(self::EXIT_CONFIG_ERROR);
            return;
        } catch (ParseException $e) {
            $this->climate->error("Config file ({$configPath}) had an invalid format and couldn't be parsed.");
            yield new CoroutineResult(self::EXIT_CONFIG_ERROR);
            return;
        }

        if ($args->exists("server")) {
            $config["server"] = $args->get("server");
        }

        if ($args->exists("storage")) {
            $config["storage"] = $args->get("storage");
        }

        if (!isset($config["server"])) {
            $this->climate->error("Config file ({$configPath}) didn't have a 'server' set nor was it passed as command line argument.");
            yield new CoroutineResult(self::EXIT_CONFIG_ERROR);
            return;
        }

        if (!isset($config["storage"])) {
            $this->climate->error("Config file ({$configPath}) didn't have a 'storage' set nor was it passed as command line argument.");
            yield new CoroutineResult(self::EXIT_CONFIG_ERROR);
            return;
        }

        if (!isset($config["email"])) {
            $this->climate->error("Config file ({$configPath}) didn't have a 'email' set.");
            yield new CoroutineResult(self::EXIT_CONFIG_ERROR);
            return;
        }

        if (!isset($config["certificates"]) || !is_array($config["certificates"])) {
            $this->climate->error("Config file ({$configPath}) didn't have a 'certificates' section that's an array.");
            yield new CoroutineResult(self::EXIT_CONFIG_ERROR);
            return;
        }

        $command = implode(" ", array_map("escapeshellarg", [
            PHP_BINARY,
            $GLOBALS["argv"][0],
            "setup",
            "--server",
            $config["server"],
            "--storage",
            $config["storage"],
            "--email",
            $config["email"],
        ]));

        $process = new Process($command);
        $result = (yield $process->exec(Process::BUFFER_ALL));

        if ($result->exit !== 0) {
            $this->climate->error("Registration failed ({$result->exit})");
            $this->climate->error($command);
            $this->climate->br()->out($result->stdout);
            $this->climate->br()->error($result->stderr);
            yield new CoroutineResult(self::EXIT_SETUP_ERROR);
            return;
        }

        $certificateChunks = array_chunk($config["certificates"], 10, true);

        $errors = [];
        $values = [];

        foreach ($certificateChunks as $certificateChunk) {
            $promises = [];

            foreach ($certificateChunk as $certificate) {
                $promises[] = \Amp\resolve($this->checkAndIssue($certificate, $server, $storage));
            }

            list($chunkErrors, $chunkValues) = (yield \Amp\any($promises));

            $errors += $chunkErrors;
            $values += $chunkValues;
        }

        $status = [
            "no_change" => count(array_filter($values, function($value) { return $value === self::STATUS_NO_CHANGE; })),
            "renewed" => count(array_filter($values, function($value) { return $value === self::STATUS_RENEWED; })),
            "failure" => count($errors),
        ];

        if ($status["renewed"] > 0) {
            foreach ($values as $i => $value) {
                if ($value === self::STATUS_RENEWED) {
                    $certificate = $config["certificates"][$i];
                    $this->climate->info("Certificate for " . implode(", ", array_keys($this->toDomainPathMap($certificate["paths"]))) . " successfully renewed.");
                }
            }
        }

        if ($status["failure"] > 0) {
            foreach ($errors as $i => $error) {
                $certificate = $config["certificates"][$i];
                $this->climate->error("Issuance for the following domains failed: " . implode(", ", array_keys($this->toDomainPathMap($certificate["paths"]))));
                $this->climate->error("Reason: {$error}");
            }

            $exitCode = $status["renewed"] > 0
                ? self::EXIT_ISSUANCE_PARTIAL
                : self::EXIT_ISSUANCE_ERROR;

            yield new CoroutineResult($exitCode);
            return;
        }

        if ($status["renewed"] > 0) {
            yield new CoroutineResult(self::EXIT_ISSUANCE_OK);
            return;
        }
    }

    /**
     * @param array  $certificate certificate configuration
     * @param string $server server to use for issuance
     * @param string $storage storage directory
     * @return \Generator
     * @throws AcmeException if something does wrong
     */
    private function checkAndIssue(array $certificate, $server, $storage) {
        $domainPathMap = $this->toDomainPathMap($certificate["paths"]);
        $domains = array_keys($domainPathMap);
        $commonName = reset($domains);

        $args = [
            PHP_BINARY,
            $GLOBALS["argv"][0],
            "check",
            "--server",
            $server,
            "--storage",
            $storage,
            "--name",
            $commonName,
        ];

        $command = implode(" ", array_map("escapeshellarg", $args));

        $process = new Process($command);
        $result = (yield $process->exec(Process::BUFFER_ALL));

        if ($result->exit === 0) {
            // No need for renewal
            yield new CoroutineResult(self::STATUS_NO_CHANGE);
            return;
        }

        if ($result->exit === 1) {
            // Renew certificate
            $args = [
                PHP_BINARY,
                $GLOBALS["argv"][0],
                "issue",
                "--server",
                $server,
                "--storage",
                $storage,
                "--domains",
                implode(",", $domains),
                "--path",
                implode(PATH_SEPARATOR, array_values($domainPathMap)),
            ];

            if (isset($certificate["user"])) {
                $args[] = "--user";
                $args[] = $certificate["user"];
            }

            if (isset($certificate["bits"])) {
                $args[] = "--bits";
                $args[] = $certificate["bits"];
            }

            $command = implode(" ", array_map("escapeshellarg", $args));

            $process = new Process($command);
            $result = (yield $process->exec(Process::BUFFER_ALL));

            if ($result->exit !== 0) {
                throw new AcmeException("Unexpected exit code ({$result->exit}) for '{$command}'." . PHP_EOL . $result->stdout . PHP_EOL . PHP_EOL . $result->stderr);
            }

            yield new CoroutineResult(self::STATUS_RENEWED);
            return;
        }

        throw new AcmeException("Unexpected exit code ({$result->exit}) for '{$command}'." . PHP_EOL . $result->stdout . PHP_EOL . PHP_EOL . $result->stderr);
    }

    private function toDomainPathMap(array $paths) {
        $result = [];

        foreach ($paths as $path => $domains) {
            if (is_numeric($path)) {
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

    public static function getDefinition() {
        $server = \Kelunik\AcmeClient\getArgumentDescription("server");
        $storage = \Kelunik\AcmeClient\getArgumentDescription("storage");

        $server["required"] = false;
        $storage["required"] = false;

        $args = [
            "server" => $server,
            "storage" => $storage,
            "config" => [
                "prefix" => "c",
                "longPrefix" => "config",
                "description" => "Configuration file to read.",
                "required" => true,
            ],
        ];

        $configPath = \Kelunik\AcmeClient\getConfigPath();

        if ($configPath) {
            $args["config"]["required"] = false;
            $args["config"]["defaultValue"] = $configPath;
        }

        return $args;
    }
}