<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Workers\Steps;

use Closure;
use Igne\LaravelBootUp\Config\WorkersConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ShellCommand;
use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\CommandRewriter;

/**
 * Starts a tracked Horizon supervisor when laravel/horizon is a project
 * dependency. Detect-and-skip: projects without Horizon never notice
 * this step.
 */
final class StartHorizon implements Step
{
    private const LABEL = 'horizon';

    public function __construct(
        private readonly WorkersConfig $config,
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

        $command = $this->rewriter->rewrite(
            ShellCommand::make(['php', 'artisan', 'horizon'])->withTimeout(null),
            $context->commandRewrites(),
        );

        $record = $this->config->horizonRunIn === 'terminal'
            ? $this->runner->startInTerminal($command, self::LABEL)
            : $this->runner->start($command, self::LABEL);

        terminal()->success("Horizon started (PID {$record->pid}) — {$record->outputLocation()}");

        return $next($context);
    }

    private function alreadyRunning(): bool
    {
        return $this->ledger->withLabel(self::LABEL)
            ->contains(fn (ProcessRecord $record): bool => $this->reaper->isAlive($record));
    }
}
