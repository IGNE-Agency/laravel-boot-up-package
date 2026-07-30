<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Queue\Steps;

use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Concerns\LaunchesAsWorker;
use Igne\LaravelBootUp\Config\QueueConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Contracts\Worker;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Enums\RunMode;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Serve\WorkerLauncher;
use Igne\LaravelBootUp\Workers\HorizonPresence;
use Illuminate\Contracts\Config\Repository;

#[Stage(ServeStage::Services)]
#[Group('workers')]
final class QueueWorker implements Step, Worker
{
    use LaunchesAsWorker;

    private const string LABEL = 'queue-worker';

    /** Memoised: both the command tokens and the display name derive from it. */
    private ?string $connection = null;

    public function __construct(
        private readonly QueueConfig $config,
        private readonly EnvFile $envFile,
        private readonly Repository $laravelConfig,
        private readonly HorizonPresence $horizon,
        private readonly WorkerLauncher $workers,
    ) {}

    public function label(): string
    {
        return self::LABEL;
    }

    public function name(): string
    {
        return "Queue worker on [{$this->connection()}]";
    }

    public function command(): CommandLine
    {
        return CommandLine::make(['php', 'artisan', 'queue:work', $this->connection()])
            ->withOptions($this->config->flags);
    }

    public function runIn(): RunMode
    {
        return $this->config->runIn;
    }

    public function streamName(): string
    {
        return 'queue';
    }

    protected function shouldRun(ServeContext $context): bool
    {
        $reason = $this->skipReason($context);

        if ($reason !== null) {
            terminal()->note($reason);

            return false;
        }

        return true;
    }

    protected function launcher(): WorkerLauncher
    {
        return $this->workers;
    }

    /**
     * The note explaining why no worker is needed, or null to start one.
     */
    private function skipReason(ServeContext $context): ?string
    {
        return match (true) {
            ! $context->options->withQueue => 'Queue worker skipped (--without-queue).',
            ! $this->config->enabled => 'Queue worker disabled in configuration — skipping.',
            $this->horizon->managesQueue() => 'laravel/horizon manages the queue — skipping queue:work.',
            $this->connection() === 'sync' => 'Queue connection is sync — no worker needed.',
            default => null,
        };
    }

    private function connection(): string
    {
        return $this->connection ??= $this->envFile->valueOr('QUEUE_CONNECTION', (string) $this->laravelConfig->get('queue.default'));
    }
}
