<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Igne\LaravelBootUp\Data\CombinedService;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\WorkerDefinition;
use Igne\LaravelBootUp\Enums\RunMode;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\CommandRewriter;

/**
 * The one way steps start tracked long-running workers: skip when a live
 * one already holds the label, rewrite the command for the context's
 * server, then either queue it for the combined output stream, start it
 * in a terminal window, or start it in the background — and report.
 */
final class WorkerLauncher
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly CommandRewriter $rewriter,
        private readonly ProcessLedger $ledger,
        private readonly ProcessReaper $reaper,
        private readonly CombinedRunPlan $plan,
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
     * or null when skipped as already running or queued for the combined
     * stream (which starts after the boot pipeline completes).
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

        $record = match ($this->effectiveMode($worker, $context)) {
            RunMode::Combined => $this->queueForCombined($worker, $command),
            RunMode::Terminal => $this->runner->startInTerminal($command, $worker->label),
            RunMode::Background => $this->runner->start($command, $worker->label),
        };

        if ($record !== null) {
            terminal()->success("{$worker->name} started (PID {$record->pid}) — {$record->outputLocation()}");
        }

        return $record;
    }

    /**
     * Combined mode needs an interactive terminal to stream into; under
     * --detach or a piped stdout the worker degrades to a plain background
     * process, exactly like the pre-combined default.
     */
    private function effectiveMode(WorkerDefinition $worker, ServeContext $context): RunMode
    {
        if ($worker->runIn === RunMode::Combined && ! $context->options->follow) {
            terminal()->note("{$worker->name}: no interactive terminal to stream into — running in the background instead.");

            return RunMode::Background;
        }

        return $worker->runIn;
    }

    private function queueForCombined(WorkerDefinition $worker, CommandLine $command): null
    {
        // FORCE_COLOR keeps npm tooling (vite) colorful without a TTY;
        // artisan workers honor it through Symfony Console as well.
        $this->plan->add(CombinedService::process(
            $worker->label,
            $worker->streamName(),
            $command->withEnv(['FORCE_COLOR' => '1']),
        ));

        terminal()->success("{$worker->name} will stream here as [{$worker->streamName()}] once the boot completes.");

        return null;
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
