<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process\Terminal;

use Illuminate\Process\Factory;

final class MacTerminal implements TerminalLauncher
{
    public function __construct(private readonly Factory $processes) {}

    public function available(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
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
