<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Services\Steps;

use Closure;
use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRecord;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Igne\LaravelBootUp\Services\ServicesConfig;

/**
 * Starts a tracked Horizon supervisor when laravel/horizon is a project
 * dependency. Detect-and-skip: projects without Horizon never notice
 * this step.
 */
final class StartHorizon implements Step
{
    private const LABEL = 'horizon';

    public function __construct(
        private readonly ServicesConfig $config,
        private readonly ComposerJson $composerJson,
        private readonly ProcessRunner $runner,
        private readonly CommandRewriter $rewriter,
        private readonly ProcessLedger $ledger,
        private readonly ProcessReaper $reaper,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if (! $this->config->horizonEnabled || ! $this->composerJson->requires('laravel/horizon')) {
            return $next($context);
        }

        if ($this->alreadyRunning()) {
            terminal()->note('Horizon already running — skipping.');

            return $next($context);
        }

        $record = $this->runner->start(
            $this->rewriter->rewrite(
                ShellCommand::make(['php', 'artisan', 'horizon'])->withTimeout(null),
                $context->server?->commandRewrites(),
            ),
            self::LABEL,
        );

        terminal()->success("Horizon started (PID {$record->pid}) — logs: storage/logs/boot-up/".self::LABEL.'.log');

        return $next($context);
    }

    private function alreadyRunning(): bool
    {
        return $this->ledger->withLabel(self::LABEL)
            ->contains(fn (ProcessRecord $record): bool => $this->reaper->isAlive($record));
    }
}
