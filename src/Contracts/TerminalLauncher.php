<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

interface TerminalLauncher
{
    public function available(): bool;

    /**
     * Open a new terminal window running the given shell command string.
     * Returns an opaque handle identifying the window for close(), or null
     * when the launcher cannot identify the window it just opened.
     */
    public function open(string $command, ?string $directory = null): ?string;

    /**
     * Close a window by the handle open() returned. A null or
     * unrecognized handle is a no-op. Best-effort: never throws.
     */
    public function close(?string $handle): void;
}
