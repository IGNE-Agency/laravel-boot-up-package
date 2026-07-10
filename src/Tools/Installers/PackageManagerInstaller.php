<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Tools\Installers;

use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\ShellCommand;
use Igne\LaravelBootstrap\Tools\InstallsTool;
use Igne\LaravelBootstrap\Tools\Tool;
use Igne\LaravelBootstrap\Tools\ToolInspector;
use Igne\LaravelBootstrap\Tools\VersionConstraint;

/**
 * One installer for the frontend package managers (bun, yarn, npm).
 * Instances are produced by ToolRegistry with the concrete Tool case.
 */
final class PackageManagerInstaller implements InstallsTool
{
    public function __construct(
        private readonly Tool $tool,
        private readonly ToolInspector $inspector,
        private readonly Homebrew $homebrew,
        private readonly ProcessRunner $processes,
    ) {}

    public function id(): string
    {
        return $this->tool->value;
    }

    public function label(): string
    {
        return $this->tool->label();
    }

    public function updatesAutomatically(): bool
    {
        return $this->tool->updatesAutomatically();
    }

    public function isInstalled(): bool
    {
        return $this->inspector->isInstalled($this->tool);
    }

    public function installedVersion(): ?string
    {
        return $this->inspector->installedVersion($this->tool);
    }

    public function install(VersionConstraint $constraint): void
    {
        $this->installOrUpdate(update: false);
    }

    public function update(VersionConstraint $constraint): void
    {
        $this->installOrUpdate(update: true);
    }

    /**
     * npm rides along with Node, so both install and update mean
     * "npm install -g npm"; bun and yarn are plain brew formulae.
     */
    private function installOrUpdate(bool $update): void
    {
        if ($this->tool === Tool::NPM) {
            $this->processes->run(ShellCommand::make(['npm', 'install', '-g', 'npm'])->withTimeout(null));

            return;
        }

        $update
            ? $this->homebrew->upgrade($this->tool->binary())
            : $this->homebrew->install($this->tool->binary());
    }
}
