<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\Process;
use Kelunik\Certificate\Certificate;
use League\CLImate\Argument\Manager;
use Psr\Log\LoggerInterface;

class Renew implements Command {
    private $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    public function execute(Manager $args) {
        return \Amp\resolve($this->doExecute($args));
    }

    private function doExecute(Manager $args) {
        $path = dirname(dirname(__DIR__)) . "/data/certs";

        if (!realpath($path)) {
            throw new \RuntimeException("Certificate path doesn't exist: '{$path}'");
        }

        $domains = (yield \Amp\File\scandir($path));
        $promises = [];

        foreach ($domains as $domain) {
            $pem = (yield \Amp\File\get($path . "/" . $domain . "/cert.pem"));
            $cert = new Certificate($pem);

            if ($cert->getValidTo() > time() + 30 * 24 * 60 * 60) {
                $this->logger->info("Certificate for " . implode(",", $cert->getNames()) . " is still valid for more than 30 days.");

                continue;
            }

            $json = (yield \Amp\File\get($path . "/" . $domain . "/config.json"));
            $config = json_decode($json);

            $command = [
                PHP_BINARY,
                dirname(dirname(__DIR__)) . "/bin/acme",
                "issue",
                "-d",
                implode(",", $config->domains),
                "-p",
                $config->path,
                "-u",
                $config->user,
            ];

            $command = array_map("escapeshellarg", $command);
            $command = implode(" ", $command);

            $promises[] = \Amp\pipe((new Process($command))->exec()->watch(function ($update) {
                list($type, $data) = $update;

                if ($type === "err") {
                    $this->logger->error($data);
                } else {
                    $this->logger->info($data);
                }
            }), function ($result) use ($command) {
                $result->command = $command;

                return $result;
            });
        }

        $results = (yield \Amp\all($promises));

        foreach ($results as $result) {
            if ($result->exit !== 0) {
                throw new \RuntimeException("Invalid exit code: " . $result->exit . " (" . $result->command . ")");
            }
        }
    }

    public static function getDefinition() {
        return [];
    }
}