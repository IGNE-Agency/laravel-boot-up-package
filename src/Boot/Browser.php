<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Boot;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Services\Platform;

final class Browser
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Platform $platform,
    ) {}

    public function open(string $url): void
    {
        $this->runner->runSilently(CommandLine::make([$this->opener(), $url]));
    }

    /**
     * WSL is the interesting case: it reports Linux and usually has no
     * xdg-open, but it can hand a URL to the Windows host. wslview ships with
     * wslu and falls back through the shell to explorer.exe, which is the one
     * thing every WSL install can reach.
     */
    private function opener(): string
    {
        return match (true) {
            $this->platform->isMacos() => 'open',
            $this->platform->isWindows() => 'explorer.exe',
            $this->isWsl() => 'wslview',
            default => 'xdg-open',
        };
    }

    private function isWsl(): bool
    {
        return ($_SERVER['WSL_DISTRO_NAME'] ?? '') !== '' || is_file('/proc/sys/fs/binfmt_misc/WSLInterop');
    }
}
