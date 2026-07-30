<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools\Installers;

use Igne\LaravelBootUp\Data\VersionConstraint;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Tools\ToolInspector;

/**
 * One installer for every tool that is a plain Homebrew formula or cask
 * (Node, Docker, Herd). Instances are produced by ToolRegistry with the
 * concrete Tool case.
 */
final class HomebrewInstaller extends ToolInstaller
{
    public function __construct(
        private readonly Tool $tool,
        ToolInspector $inspector,
        private readonly Homebrew $homebrew,
        private readonly bool $cask = false,
    ) {
        parent::__construct($inspector);
    }

    protected function tool(): Tool
    {
        return $this->tool;
    }

    public function install(VersionConstraint $constraint): void
    {
        $this->homebrew->install($this->tool->binary(), cask: $this->cask);
    }

    /**
     * Tools that ship their own updater (Docker, Herd) are never
     * brew-upgraded — brew would fight it.
     */
    public function update(VersionConstraint $constraint): void
    {
        if (! $this->tool->updatesAutomatically()) {
            $this->homebrew->upgrade($this->tool->binary());
        }
    }
}
