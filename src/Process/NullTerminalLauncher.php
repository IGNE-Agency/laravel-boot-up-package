<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process;

use Igne\LaravelBootUp\Contracts\TerminalLauncher;
use LogicException;

final class NullTerminalLauncher implements TerminalLauncher
{
    public function available(): bool
    {
        return false;
    }

    public function open(string $command, ?string $directory = null): ?string
    {
        throw new LogicException('No terminal emulator is available on this system.');
    }

    public function close(?string $handle): void
    {
        // No-op: this launcher never opens a window.
    }
}
