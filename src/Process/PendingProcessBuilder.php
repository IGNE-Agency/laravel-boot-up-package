<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process;

use Igne\LaravelBootUp\Data\CommandLine;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;

/**
 * Turns a CommandLine into a configured PendingProcess — the one translation
 * between the package's command description and Illuminate's process API,
 * shared by the runner and the output multiplexer.
 */
final class PendingProcessBuilder
{
    /**
     * @param  list<string>|null  $tokens  overrides the command's own tokens (shell wrappers)
     */
    public static function build(Factory $processes, CommandLine $command, ?array $tokens = null): PendingProcess
    {
        $pending = $processes->command($tokens ?? $command->tokens);

        if ($command->cwd !== null) {
            $pending = $pending->path($command->cwd);
        }

        if ($command->env !== []) {
            $pending = $pending->env($command->env);
        }

        return $command->timeout === null
            ? $pending->forever()
            : $pending->timeout($command->timeout);
    }
}
