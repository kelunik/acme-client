<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\CoroutineResult;
use Amp\File\FilesystemException;
use Amp\Process;
use Kelunik\Acme\AcmeException;
use League\CLImate\Argument\Manager;
use League\CLImate\CLImate;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class Auto implements Command {
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
        $server = $args->get("server");
        $storage = $args->get("storage");
        $configPath = $args->get("config");

        try {
            $config = Yaml::parse(
                yield \Amp\File\get($configPath)
            );
        } catch (FilesystemException $e) {
            $this->climate->error("Config file ({$configPath}) not found.");
            yield new CoroutineResult(1);
            return;
        } catch (ParseException $e) {
            $this->climate->error("Config file ({$configPath}) had an invalid format and couldn't be parsed.");
            yield new CoroutineResult(1);
            return;
        }

        if (!isset($config["email"])) {
            $this->climate->error("Config file ({$configPath}) didn't have a 'email' set.");
            yield new CoroutineResult(2);
            return;
        }

        if (!isset($config["certificates"]) || !is_array($config["certificates"])) {
            $this->climate->error("Config file ({$configPath}) didn't have a 'certificates' section that's an array.");
            yield new CoroutineResult(2);
            return;
        }

        $command = implode(" ", array_map("escapeshellarg", [
            PHP_BINARY,
            $GLOBALS["argv"][0],
            "setup",
            "--server",
            $server,
            "--storage",
            $storage,
            "--email",
            $config["email"],
        ]));

        $process = new Process($command);
        $result = (yield $process->exec(Process::BUFFER_ALL));

        if ($result->exit !== 0) {
            $this->climate->error("Registration failed ({$result->exit})");
            $this->climate->error($command);
            $this->climate->br()->out($result->out);
            $this->climate->br()->error($result->err);
            yield new CoroutineResult(3);
            return;
        }

        $certificateChunks = array_chunk($config["certificates"], 10);

        $errors = [];

        foreach ($certificateChunks as $chunk) {
            $promises = [];

            foreach ($chunk as $certificate) {
                $promises[] = \Amp\resolve($this->checkAndIssue($certificate, $server, $storage));
            }

            list($errors) = (yield \Amp\any($promises));
            $errors = array_merge($errors, $errors);
        }

        if (!empty($errors)) {
            foreach ($errors as $i => $error) {
                $certificate = $config["certificates"][$i];
                $this->climate->error("Issuance for the following domains failed: " . implode(", ", array_keys($this->toDomainPathMap($certificate["paths"]))));
                $this->climate->error("Reason: {$error}");
            }

            yield new CoroutineResult(3);
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
        $commonName = reset(array_keys($domainPathMap));

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
        $result = (yield $process->exec());

        if ($result->exit === 0) {
            // No need for renewal
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
                implode(",", array_keys($domainPathMap)),
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
            $result = (yield $process->exec());

            if ($result->exit !== 0) {
                throw new AcmeException("Unexpected exit code ({$result->exit}) for '{$command}'.");
            }

            return;
        }

        throw new AcmeException("Unexpected exit code ({$result->exit}) for '{$command}'.");
    }

    private function toDomainPathMap(array $paths) {
        $result = [];

        foreach ($paths as $path => $domains) {
            $domains = (array) $domains;

            foreach ($domains as $domain) {
                if (isset($result[$domain])) {
                    throw new \LogicException("Duplicate domain: {$domain}");
                }

                $result[$domain] = $path;
            }
        }

        return $result;
    }

    public static function getDefinition() {
        $args = [
            "server" => \Kelunik\AcmeClient\getArgumentDescription("server"),
            "storage" => \Kelunik\AcmeClient\getArgumentDescription("storage"),
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