<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools\Installers;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\VersionConstraint;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Tools\ToolInspector;

final class ComposerInstaller extends ToolInstaller
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
        return Tool::Composer;
    }

    public function install(VersionConstraint $constraint): void
    {
        $this->homebrew->install('composer');
    }

    public function update(VersionConstraint $constraint): void
    {
        $this->processes->run(CommandLine::make(['composer', 'self-update'])->withTimeout(null));
    }
}
