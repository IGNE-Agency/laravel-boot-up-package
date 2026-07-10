<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Tools\Installers;

use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\ShellCommand;

use function Laravel\Prompts\info;

/**
 * The single Homebrew seam: bootstraps brew itself when missing and runs
 * formula installs/upgrades for the built-in installers.
 */
final class Homebrew
{
    public function __construct(
        private readonly ProcessRunner $processes,
    ) {}

    public function ensureInstalled(): void
    {
        if ($this->processes->isCommandAvailable('brew')) {
            return;
        }

        info('Homebrew not found. Installing...');

        $this->processes->run(
            ShellCommand::make([
                'bash',
                '-c',
                'curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh | bash',
            ])->withTimeout(null),
        );
    }

    public function install(string $formula, bool $cask = false): void
    {
        $this->ensureInstalled();

        $tokens = $cask
            ? ['brew', 'install', '--cask', $formula]
            : ['brew', 'install', $formula];

        $this->processes->run(ShellCommand::make($tokens)->withTimeout(null));
    }

    public function upgrade(string $formula): void
    {
        $this->ensureInstalled();

        $this->processes->run(ShellCommand::make(['brew', 'upgrade', $formula])->withTimeout(null));
    }
}
