<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools\Installers;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\VersionConstraint;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Tools\ToolInspector;

final class PhpInstaller extends ToolInstaller
{
    public function __construct(
        ToolInspector $inspector,
        private readonly Homebrew $homebrew,
        private readonly ProcessRunner $processes,
    ) {
        parent::__construct($inspector);
    }

    protected function tool(): Tool
    {
        return Tool::Php;
    }

    public function install(VersionConstraint $constraint): void
    {
        if ($this->installViaHerd()) {
            return;
        }

        $this->homebrew->install('php');
    }

    public function update(VersionConstraint $constraint): void
    {
        if ($this->installViaHerd()) {
            return;
        }

        $this->homebrew->upgrade('php');
    }

    /**
     * When Herd manages this machine, PHP must be installed through Herd —
     * a brew PHP next to Herd's own binaries breaks its version switching.
     */
    private function installViaHerd(): bool
    {
        if (! $this->inspector->isInstalled(Tool::Herd)) {
            return false;
        }

        $this->processes->run(CommandLine::make(['herd', 'php:install'])->withTimeout(null));

        return true;
    }
}
