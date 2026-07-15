<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process\Terminal;

use LogicException;

final class NullTerminal implements TerminalLauncher
{
    public function available(): bool
    {
        return false;
    }

    public function open(string $command, ?string $directory = null): void
    {
        throw new LogicException('No terminal emulator is available on this system.');
    }
}
