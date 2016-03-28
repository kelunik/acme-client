<?php

namespace Kelunik\AcmeClient\Commands;

use League\CLImate\Argument\Manager;
use League\CLImate\CLImate;
use RuntimeException;

class Version implements Command {
    private $climate;

    public function __construct(CLImate $climate) {
        $this->climate = $climate;
    }

    public function execute(Manager $args) {
        $version = $this->getVersion();

        $buildTime = $this->readFileOr("info/build.time", time());
        $buildDate = date('M jS Y H:i:s T', (int) trim($buildTime));

        $package = json_decode($this->readFileOr("composer.json", new RuntimeException("No composer.json found.")));

        $this->climate->out("┌ <green>kelunik/acme-client</green> @ <yellow>{$version}</yellow> (built: {$buildDate})");
        $this->climate->out(($args->defined("deps") ? "│" : "└") . " " . $this->getDescription($package));

        if ($args->defined("deps")) {
            $lockFile = json_decode($this->readFileOr("composer.lock", new RuntimeException("No composer.lock found.")));
            $packages = $lockFile->packages;

            for ($i = 0; $i < count($packages); $i++) {
                $link = $i === count($packages) - 1 ? "└──" : "├──";
                $this->climate->out("{$link} <green>{$packages[$i]->name}</green> @ <yellow>{$packages[$i]->version}</yellow>");

                $link = $i === count($packages) - 1 ? "   " : "│  ";
                $this->climate->out("{$link} " . $this->getDescription($packages[$i]));
            }
        }
    }

    private function getDescription($package) {
        return \Kelunik\AcmeClient\ellipsis(isset($package->description) ? $package->description : "");
    }

    private function getVersion() {
        if (file_exists(__DIR__ . "/../../.git")) {
            $version = `git describe --tags`;
        } else {
            $version = $this->readFileOr("info/build.version", "-unknown");
        }

        return substr(trim($version), 1);
    }

    private function readFileOr($file, $default = "") {
        if (file_exists(__DIR__ . "/../../" . $file)) {
            return file_get_contents(__DIR__ . "/../../" . $file);
        } else {
            if ($default instanceof \Exception || $default instanceof \Throwable) {
                throw $default;
            }

            return $default;
        }
    }

    public static function getDefinition() {
        return [
            "deps" => [
                "longPrefix" => "deps",
                "description" => "Show also the bundled dependency versions.",
                "noValue" => true,
            ],
        ];
    }
}