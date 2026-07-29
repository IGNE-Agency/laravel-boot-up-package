<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Queue\Steps;

use Closure;
use Igne\LaravelBootUp\Config\QueueConfig;
use Igne\LaravelBootUp\Config\WorkersConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\WorkerDefinition;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Serve\WorkerLauncher;
use Illuminate\Contracts\Config\Repository;

final class StartQueueWorker implements Step
{
    private const LABEL = 'queue-worker';

    public function __construct(
        private readonly QueueConfig $config,
        private readonly EnvFile $envFile,
        private readonly Repository $laravelConfig,
        private readonly WorkersConfig $workers,
        private readonly ComposerJson $composerJson,
        private readonly WorkerLauncher $launcher,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        $connection = $this->connection();

        $reason = $this->skipReason($context, $connection);

        if ($reason !== null) {
            terminal()->note($reason);

            return $next($context);
        }

        $this->launcher->launch($this->worker($connection), $context);

        return $next($context);
    }

    /**
     * The note explaining why no worker is needed, or null to start one.
     */
    private function skipReason(ServeContext $context, string $connection): ?string
    {
        return match (true) {
            ! $context->options->withQueue => 'Queue worker skipped (--without-queue).',
            ! $this->config->enabled => 'Queue worker disabled in configuration — skipping.',
            $this->workers->horizonEnabled && $this->composerJson->requires('laravel/horizon') => 'laravel/horizon manages the queue — skipping queue:work.',
            $connection === 'sync' => 'Queue connection is sync — no worker needed.',
            default => null,
        };
    }

    private function worker(string $connection): WorkerDefinition
    {
        return new WorkerDefinition(
            label: self::LABEL,
            name: "Queue worker on [{$connection}]",
            tokens: ['php', 'artisan', 'queue:work', $connection],
            runIn: $this->config->runIn,
            streamAs: 'queue',
            options: $this->config->flags,
        );
    }

    private function connection(): string
    {
        return $this->envFile->valueOr('QUEUE_CONNECTION', (string) $this->laravelConfig->get('queue.default'));
    }
}
