<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Workers\Steps;

use Closure;
use Igne\LaravelBootUp\Config\WorkersConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\WorkerDefinition;
use Igne\LaravelBootUp\Serve\WorkerLauncher;

/**
 * Starts a tracked `schedule:work` process. Off by default — a project
 * without scheduled tasks gains nothing from a scheduler loop — and
 * enabled through the workers config (WorkersConfig).
 */
final class StartScheduler implements Step
{
    private const LABEL = 'scheduler';

    public function __construct(
        private readonly WorkersConfig $config,
        private readonly WorkerLauncher $launcher,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if ($this->config->schedulerEnabled) {
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
            runIn: $this->config->schedulerRunIn,
        );
    }
}
