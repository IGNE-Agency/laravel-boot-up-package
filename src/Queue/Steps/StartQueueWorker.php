<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Queue\Steps;

use Closure;
use Igne\LaravelBootstrap\Environment\EnvFile;
use Igne\LaravelBootstrap\Process\ProcessLedger;
use Igne\LaravelBootstrap\Process\ProcessReaper;
use Igne\LaravelBootstrap\Process\ProcessRecord;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\ShellCommand;
use Igne\LaravelBootstrap\Queue\QueueConfig;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\Step;
use Igne\LaravelBootstrap\Servers\CommandRewriter;
use Illuminate\Contracts\Config\Repository;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

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
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if (! $context->options->withQueue) {
            note('Queue worker skipped (--without-queue).');

            return $next($context);
        }

        if (! $this->config->enabled) {
            note('Queue worker disabled in configuration.');

            return $next($context);
        }

        $connection = $this->connection();

        if ($connection === 'sync') {
            note('Queue connection is sync — no worker needed.');

            return $next($context);
        }

        if ($this->workerIsRunning()) {
            note('Queue worker already running — skipping.');

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

        info("Queue worker started on [{$connection}] (PID {$record->pid}) — logs: storage/logs/bootstrap/".self::LABEL.'.log');

        return $next($context);
    }

    /**
     * The .env file wins over loaded config: when .env was created during
     * this very boot, config('queue.default') still carries the stale value.
     */
    private function connection(): string
    {
        $fromEnv = $this->envFile->get('QUEUE_CONNECTION');

        return $fromEnv !== null && $fromEnv !== ''
            ? $fromEnv
            : (string) $this->laravelConfig->get('queue.default');
    }

    private function workerIsRunning(): bool
    {
        return $this->ledger->withLabel(self::LABEL)
            ->contains(fn (ProcessRecord $record): bool => $this->reaper->isAlive($record));
    }
}
