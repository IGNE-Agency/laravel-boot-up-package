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
     * "npm install -g npm"; bun, yarn and pnpm are plain brew formulae.
     */
    private function installOrUpdate(bool $update): void
    {
        if ($this->tool === Tool::NPM) {
            $this->processes->run(CommandLine::make(['npm', 'install', '-g', 'npm'])->withTimeout(null));

            return;
        }

        $update
            ? $this->homebrew->upgrade($this->tool->binary())
            : $this->homebrew->install($this->tool->binary());
    }
}
