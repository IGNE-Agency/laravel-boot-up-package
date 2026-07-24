<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools\Installers;

use Igne\LaravelBootUp\Contracts\InstallsTool;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\VersionConstraint;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Tools\ToolInspector;

final class PhpInstaller implements InstallsTool
{
    private const Tool TOOL = Tool::PHP;

    public function __construct(
        private readonly ToolInspector $inspector,
        private readonly Homebrew $homebrew,
        private readonly ProcessRunner $processes,
    ) {}

    public function id(): string
    {
        return self::TOOL->value;
    }

    public function label(): string
    {
        return self::TOOL->label();
    }

    public function updatesAutomatically(): bool
    {
        return self::TOOL->updatesAutomatically();
    }

    public function isInstalled(): bool
    {
        return $this->inspector->isInstalled(self::TOOL);
    }

    public function installedVersion(): ?string
    {
        return $this->inspector->installedVersion(self::TOOL);
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
        if (! $this->inspector->isInstalled(Tool::HERD)) {
            return false;
        }

        $this->processes->run(CommandLine::make(['herd', 'php:install'])->withTimeout(null));

        return true;
    }
}
