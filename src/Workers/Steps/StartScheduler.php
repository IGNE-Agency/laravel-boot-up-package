<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Workers\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Config\SchedulerConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\WorkerDefinition;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Serve\WorkerLauncher;

/**
 * Starts a tracked `schedule:work` process. Off by default — a project
 * without scheduled tasks gains nothing from a scheduler loop — and
 * enabled through the scheduler config (SchedulerConfig).
 */
#[Stage(ServeStage::Services)]
#[Group('workers')]
final class StartScheduler implements Step
{
    private const string LABEL = 'scheduler';

    public function __construct(
        private readonly SchedulerConfig $config,
        private readonly WorkerLauncher $launcher,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if ($this->config->enabled) {
            $this->launcher->launch($this->worker(), $context);
        }

        return $next($context);
    }

    private function worker(): WorkerDefinition
    {
        return new WorkerDefinition(
            label: self::LABEL,
            name: 'Scheduler',
            tokens: ['php', 'artisan', 'schedule:work'],
            runIn: $this->config->runIn,
        );
    }
}
