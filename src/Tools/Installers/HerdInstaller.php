<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Tools\Installers;

use Igne\LaravelBootstrap\Tools\InstallsTool;
use Igne\LaravelBootstrap\Tools\Tool;
use Igne\LaravelBootstrap\Tools\ToolInspector;
use Igne\LaravelBootstrap\Tools\VersionConstraint;

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
