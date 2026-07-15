<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools\Installers;

use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;
use Igne\LaravelBootUp\Tools\InstallsTool;
use Igne\LaravelBootUp\Tools\Tool;
use Igne\LaravelBootUp\Tools\ToolInspector;
use Igne\LaravelBootUp\Tools\VersionConstraint;

final class ComposerInstaller implements InstallsTool
{
    private const Tool TOOL = Tool::COMPOSER;

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
        $this->homebrew->install('composer');
    }

    public function update(VersionConstraint $constraint): void
    {
        $this->processes->run(ShellCommand::make(['composer', 'self-update'])->withTimeout(null));
    }
}
