<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Workers\Steps;

use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Concerns\LaunchesAsWorker;
use Igne\LaravelBootUp\Config\SchedulerConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Contracts\Worker;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Enums\RunMode;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Serve\WorkerLauncher;

/**
 * A tracked `schedule:work` process. Off by default — a project without
 * scheduled tasks gains nothing from a scheduler loop — and enabled
 * through the scheduler config (SchedulerConfig).
 */
#[Stage(ServeStage::Services)]
#[Group('workers')]
final class SchedulerWorker implements Step, Worker
{
    use LaunchesAsWorker;

    private const string LABEL = 'scheduler';

    public function __construct(
        private readonly SchedulerConfig $config,
        private readonly WorkerLauncher $workers,
    ) {}

    public function label(): string
    {
        return self::LABEL;
    }

    public function name(): string
    {
        return 'Scheduler';
    }

    public function command(): CommandLine
    {
        return CommandLine::make(['php', 'artisan', 'schedule:work']);
    }

    public function runIn(): RunMode
    {
        return $this->config->runIn;
    }

    protected function shouldRun(ServeContext $context): bool
    {
        return $this->config->enabled;
    }

    protected function launcher(): WorkerLauncher
    {
        return $this->workers;
    }
}
