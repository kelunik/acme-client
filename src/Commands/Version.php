<?php

namespace Kelunik\AcmeClient\Commands;

use Amp\Promise;
use Amp\Success;
use League\CLImate\Argument\Manager;
use League\CLImate\CLImate;
use function Kelunik\AcmeClient\ellipsis;

class Version implements Command
{
    public static function getDefinition(): array
    {
        return [
            'deps' => [
                'longPrefix' => 'deps',
                'description' => 'Show also the bundled dependency versions.',
                'noValue' => true,
            ],
        ];
    }

    private $climate;

    public function __construct(CLImate $climate)
    {
        $this->climate = $climate;
    }

    public function execute(Manager $args): Promise
    {
        $version = $this->getVersion();

        $buildTime = $this->readFileOr('info/build.time', \time());
        $buildDate = \date('M jS Y H:i:s T', (int) \trim($buildTime));

        $package = \json_decode($this->readFileOr('composer.json', new \Exception('No composer.json found.')));

        $this->climate->out("┌ <green>kelunik/acme-client</green> @ <yellow>{$version}</yellow> (built: {$buildDate})");
        $this->climate->out(($args->defined('deps') ? '│' : '└') . ' ' . $this->getDescription($package));

        if ($args->defined('deps')) {
            $lockFile = \json_decode($this->readFileOr('composer.lock', new \Exception('No composer.lock found.')));
            $packages = $lockFile->packages;

            for ($i = 0, $count = \count($packages); $i < $count; $i++) {
                $link = $i === $count - 1 ? '└──' : '├──';
                $this->climate->out("{$link} <green>{$packages[$i]->name}</green> @ <yellow>{$packages[$i]->version}</yellow>");

                $link = $i === $count - 1 ? '   ' : '│  ';
                $this->climate->out("{$link} " . $this->getDescription($packages[$i]));
            }
        }

        return new Success;
    }

    private function getDescription($package): string
    {
        return ellipsis($package->description ?? '');
    }

    private function getVersion(): string
    {
        if (\file_exists(__DIR__ . '/../../.git')) {
            $version = \shell_exec("git describe --tags");
        } else {
            $version = $this->readFileOr('info/build.version', '-unknown');
        }

        return \substr(\trim($version), 1);
    }

    private function readFileOr(string $file, $default = '')
    {
        if (\file_exists(__DIR__ . '/../../' . $file)) {
            return \file_get_contents(__DIR__ . '/../../' . $file);
        }

        if ($default instanceof \Throwable) {
            throw $default;
        }

        return $default;
    }
}
