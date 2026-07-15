<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools\Installers;

use Igne\LaravelBootUp\Tools\InstallsTool;
use Igne\LaravelBootUp\Tools\Tool;
use Igne\LaravelBootUp\Tools\ToolInspector;
use Igne\LaravelBootUp\Tools\VersionConstraint;

final class HerdInstaller implements InstallsTool
{
    private const Tool TOOL = Tool::HERD;

    public function __construct(
        private readonly ToolInspector $inspector,
        private readonly Homebrew $homebrew,
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
        $this->homebrew->install('herd', cask: true);
    }

    /**
     * Herd ships its own updater; brew upgrades would fight it.
     */
    public function update(VersionConstraint $constraint): void {}
}
