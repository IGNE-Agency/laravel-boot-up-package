<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process\Terminal;

use Igne\LaravelBootUp\Support\Platform;
use Illuminate\Process\Factory;

final class LinuxTerminal implements TerminalLauncher
{
    public function __construct(
        private readonly Factory $processes,
        private readonly Platform $platform,
    ) {}

    public function available(): bool
    {
        return $this->platform->isLinux() && $this->emulator() !== null;
    }

    public function open(string $command, ?string $directory = null): ?string
    {
        $inner = $directory !== null
            ? 'cd '.escapeshellarg($directory).' && '.$command
            : $command;

        $emulator = $this->emulator();

        $tokens = match ($emulator) {
            'gnome-terminal' => ['gnome-terminal', '--', 'sh', '-c', $inner],
            default => ['xterm', '-e', 'sh', '-c', $inner],
        };

        $this->processes->command($tokens)->run()->throw();

        // gnome-terminal/xterm windows close on their own when the shell they
        // run exits; we do not track and close individual Linux windows.
        return null;
    }

    public function close(?string $handle): void
    {
        // No-op: see open().
    }

    private function emulator(): ?string
    {
        foreach (['gnome-terminal', 'xterm'] as $candidate) {
            $result = $this->processes
                ->command(['sh', '-c', 'command -v '.$candidate])
                ->run();

            if ($result->successful()) {
                return $candidate;
            }
        }

        return null;
    }
}
