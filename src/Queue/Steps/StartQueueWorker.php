<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Queue\Steps;

use Closure;
use Igne\LaravelBootUp\Config\QueueConfig;
use Igne\LaravelBootUp\Config\ServicesConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ShellCommand;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Illuminate\Contracts\Config\Repository;

final class StartQueueWorker implements Step
{
    private const LABEL = 'queue-worker';

    public function __construct(
        private readonly QueueConfig $config,
        private readonly EnvFile $envFile,
        private readonly Repository $laravelConfig,
        private readonly ProcessRunner $runner,
        private readonly CommandRewriter $rewriter,
        private readonly ProcessLedger $ledger,
        private readonly ProcessReaper $reaper,
        private readonly ServicesConfig $services,
        private readonly ComposerJson $composerJson,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if (! $context->options->withQueue) {
            terminal()->note('Queue worker skipped (--without-queue).');

            return $next($context);
        }

        if (! $this->config->enabled) {
            terminal()->note('Queue worker disabled in configuration — skipping.');

            return $next($context);
        }

        if ($this->services->horizonEnabled && $this->composerJson->requires('laravel/horizon')) {
            terminal()->note('laravel/horizon manages the queue — skipping queue:work.');

            return $next($context);
        }

        $connection = $this->connection();

        if ($connection === 'sync') {
            terminal()->note('Queue connection is sync — no worker needed.');

            return $next($context);
        }

        if ($this->workerIsRunning()) {
            terminal()->note('Queue worker already running — skipping.');

            return $next($context);
        }

        $command = $this->rewriter->rewrite(
            ShellCommand::make(['php', 'artisan', 'queue:work', $connection])
                ->withOptions($this->config->flags)
                ->withTimeout(null),
            $context->server?->commandRewrites(),
        );

        $record = $this->config->runIn === 'terminal'
            ? $this->runner->startInTerminal($command, self::LABEL)
            : $this->runner->start($command, self::LABEL);

        terminal()->success("Queue worker started on [{$connection}] (PID {$record->pid}) — {$record->outputLocation()}");

        return $next($context);
    }

    private function connection(): string
    {
        return $this->envFile->valueOr('QUEUE_CONNECTION', (string) $this->laravelConfig->get('queue.default'));
    }

    private function workerIsRunning(): bool
    {
        return $this->ledger->withLabel(self::LABEL)
            ->contains(fn (ProcessRecord $record): bool => $this->reaper->isAlive($record));
    }
}
