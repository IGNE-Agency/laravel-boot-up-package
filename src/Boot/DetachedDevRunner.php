<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Boot;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Illuminate\Foundation\DevCommands;

/**
 * Starts the dev processes detached instead of handing them to a terminal UI.
 *
 * Laravel's dev command has no detached mode — it always runs a multiplexer in
 * the foreground — so `--detach` keeps boot-up's own machinery: every process
 * is recorded in the ledger, writes to its own log file, and is therefore
 * visible to app:status and stoppable with app:down.
 */
final class DetachedDevRunner
{
    public function __construct(private readonly ProcessRunner $runner) {}

    /**
     * @return int how many processes were started
     */
    public function run(): int
    {
        $started = 0;

        foreach (DevCommands::commands() as $process) {
            $record = $this->runner->start(
                CommandLine::make($process['command'])->withTimeout(null),
                $process['name'],
            );

            terminal()->note("[{$process['name']}] running in the background (PID {$record->pid}).");

            $started++;
        }

        return $started;
    }
}
