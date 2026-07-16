<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Services\Steps;

use Closure;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRecord;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Igne\LaravelBootUp\Services\ServicesConfig;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

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
            note('Scheduler already running — skipping.');

            return $next($context);
        }

        $command = $this->rewriter->rewrite(
            ShellCommand::make(['php', 'artisan', 'schedule:work'])->withTimeout(null),
            $context->server?->commandRewrites(),
        );

        $record = $this->config->schedulerRunIn === 'terminal'
            ? $this->runner->startInTerminal($command, self::LABEL)
            : $this->runner->start($command, self::LABEL);

        info("Scheduler started (PID {$record->pid}) — logs: storage/logs/boot-up/".self::LABEL.'.log');

        return $next($context);
    }

    private function alreadyRunning(): bool
    {
        return $this->ledger->withLabel(self::LABEL)
            ->contains(fn (ProcessRecord $record): bool => $this->reaper->isAlive($record));
    }
}
