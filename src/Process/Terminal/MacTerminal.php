<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process\Terminal;

use Igne\LaravelBootUp\Contracts\TerminalLauncher;
use Igne\LaravelBootUp\Services\Platform;
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

    public function open(string $command, ?string $directory = null): ?string
    {
        $inner = $directory !== null
            ? 'cd '.escapeshellarg($directory).' && '.$command
            : $command;

        // do script opens a new window and runs the command in it; the id of
        // the now-front window is returned so shutdown can close exactly this
        // window later.
        $script = 'tell application "Terminal"'.PHP_EOL
            .sprintf('do script "%s"', addcslashes($inner, '"\\')).PHP_EOL
            .'id of front window'.PHP_EOL
            .'end tell';

        $output = trim($this->processes
            ->command(['osascript', '-e', $script])
            ->run()
            ->throw()
            ->output());

        return ctype_digit($output) ? $output : null;
    }

    public function close(?string $handle): void
    {
        if ($handle === null || ! ctype_digit($handle)) {
            return;
        }

        // Best-effort: the process is already dead by the time we close, so
        // Terminal does not prompt about a running process; a stale id simply
        // matches no window.
        $script = sprintf(
            'tell application "Terminal" to close (every window whose id is %s)',
            $handle,
        );

        $this->processes->command(['osascript', '-e', $script])->run();
    }
}
