<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process\Terminal;

interface TerminalLauncher
{
    public function available(): bool;

    /**
     * Open a new terminal window running the given shell command string.
     */
    public function open(string $command, ?string $directory = null): void;
}
