<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools\Installers;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\VersionConstraint;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Tools\ToolInspector;

/**
 * One installer for the frontend package managers (bun, yarn, npm, pnpm).
 * Instances are produced by ToolRegistry with the concrete Tool case.
 */
final class PackageManagerInstaller extends ToolInstaller
{
    public function __construct(
        private readonly Tool $tool,
        ToolInspector $inspector,
        private readonly Homebrew $homebrew,
        private readonly ProcessRunner $processes,
    ) {
        parent::__construct($inspector);
    }

    protected function tool(): Tool
    {
        return $this->tool;
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
     * "npm install -g npm"; yarn comes from Corepack; bun and pnpm are plain
     * brew formulae.
     */
    private function installOrUpdate(bool $update): void
    {
        if ($this->tool === Tool::Npm) {
            $this->processes->run(CommandLine::make(['npm', 'install', '-g', 'npm'])->withTimeout(null));

            return;
        }

        if ($this->tool === Tool::Yarn) {
            $this->enableYarnThroughCorepack();

            return;
        }

        $update
            ? $this->homebrew->upgrade($this->tool->binary())
            : $this->homebrew->install($this->tool->binary());
    }

    /**
     * Corepack's yarn shim runs whichever version a project's package.json
     * pins, and Yarn 1 for a project that pins none — so it replaces
     * Homebrew's Yarn 1, which shadows the shim and refuses Yarn 2+ projects
     * outright. Node 25+ no longer bundles Corepack, hence the npm fallback.
     */
    private function enableYarnThroughCorepack(): void
    {
        if (! $this->processes->isCommandAvailable('corepack')) {
            $this->processes->run(CommandLine::make(['npm', 'install', '-g', 'corepack'])->withTimeout(null));
        }

        if ($this->homebrew->hasFormula('yarn')) {
            terminal()->info("Removing Homebrew's Yarn 1 — Corepack provides yarn from now on...");
            $this->homebrew->uninstall('yarn');
        }

        $this->processes->run(CommandLine::make(['corepack', 'enable', 'yarn']));
    }
}
