<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\WorkerDefinition;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\CommandRewriter;

/**
 * The one way steps start tracked long-running workers: skip when a live
 * one already holds the label, rewrite the command for the context's
 * server, start it in a terminal window or the background, and report.
 */
final class WorkerLauncher
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly CommandRewriter $rewriter,
        private readonly ProcessLedger $ledger,
        private readonly ProcessReaper $reaper,
    ) {}

    /**
     * Whether a live tracked process already holds this label.
     */
    public function isRunning(string $label): bool
    {
        return $this->ledger->withLabel($label)
            ->contains(fn (ProcessRecord $record): bool => $this->reaper->isAlive($record));
    }

    /**
     * Start the worker unless one is already running. Returns the record,
     * or null when skipped as already running.
     */
    public function launch(WorkerDefinition $worker, ServeContext $context): ?ProcessRecord
    {
        if ($this->isRunning($worker->label)) {
            terminal()->note("{$worker->name} already running — skipping.");

            return null;
        }

        $command = $this->rewriter->rewriteFor(
            $context,
            CommandLine::make($worker->tokens)
                ->withOptions($worker->options)
                ->withTimeout(null),
        );

        $record = $worker->runIn === 'terminal'
            ? $this->runner->startInTerminal($command, $worker->label)
            : $this->runner->start($command, $worker->label);

        terminal()->success("{$worker->name} started (PID {$record->pid}) — {$record->outputLocation()}");

        return $record;
    }

    /**
     * Reap every tracked process holding this label.
     */
    public function stop(string $label): void
    {
        $this->ledger->withLabel($label)
            ->each(fn (ProcessRecord $record) => $this->reaper->reap($record));
    }
}
