<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Process\Terminal;

interface TerminalLauncher
{
    public function available(): bool;

    /**
     * Open a new terminal window running the given shell command string.
     */
    public function open(string $command, ?string $directory = null): void;
}
