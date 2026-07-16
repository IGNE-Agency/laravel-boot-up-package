<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process\Terminal;

use Igne\LaravelBootUp\Support\Platform;
use Illuminate\Process\Factory;

final class MacTerminal implements TerminalLauncher
{
    public function __construct(
        private readonly Factory $processes,
        private readonly Platform $platform,
    ) {}

    public function available(): bool
    {
        return $this->platform->isMacos();
    }

    public function open(string $command, ?string $directory = null): void
    {
        $inner = $directory !== null
            ? 'cd '.escapeshellarg($directory).' && '.$command
            : $command;

        $script = sprintf(
            'tell application "Terminal" to do script "%s"',
            addcslashes($inner, '"\\'),
        );

        $this->processes
            ->command(['osascript', '-e', $script])
            ->run()
            ->throw();
    }
}
