<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Services\Steps;

use Closure;
use Igne\LaravelBootUp\Config\ServicesConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ShellCommand;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\CommandRewriter;

/**
 * Starts a tracked `schedule:work` process. Off by default — a project
 * without scheduled tasks gains nothing from a scheduler loop — and
 * enabled via boot-up.services.scheduler.
 */
final class StartScheduler implements Step
{
    private const LABEL = 'scheduler';

    public function __construct(
        private readonly ServicesConfig $config,
        private readonly ProcessRunner $runner,
        private readonly CommandRewriter $rewriter,
        private readonly ProcessLedger $ledger,
        private readonly ProcessReaper $reaper,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if (! $this->config->schedulerEnabled) {
            return $next($context);
        }

        if ($this->alreadyRunning()) {
            terminal()->note('Scheduler already running — skipping.');

            return $next($context);
        }

        $command = $this->rewriter->rewrite(
            ShellCommand::make(['php', 'artisan', 'schedule:work'])->withTimeout(null),
            $context->server?->commandRewrites(),
        );

        $record = $this->config->schedulerRunIn === 'terminal'
            ? $this->runner->startInTerminal($command, self::LABEL)
            : $this->runner->start($command, self::LABEL);

        terminal()->success("Scheduler started (PID {$record->pid}) — {$record->outputLocation()}");

        return $next($context);
    }

    private function alreadyRunning(): bool
    {
        return $this->ledger->withLabel(self::LABEL)
            ->contains(fn (ProcessRecord $record): bool => $this->reaper->isAlive($record));
    }
}
