<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process;

/**
 * The shell line a terminal-window launcher hands its emulator: cd into
 * the project first (escaped) so the command runs where the app lives.
 */
final class TerminalScript
{
    public static function inDirectory(string $command, ?string $directory): string
    {
        if ($directory === null) {
            return $command;
        }

        $cd = escapeshellarg($directory);

        return "cd {$cd} && {$command}";
    }
}
