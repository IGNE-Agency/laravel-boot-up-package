<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Deploy\Scripts;

use Igne\LaravelBootstrap\Database\DatabaseConfig;
use Igne\LaravelBootstrap\Deploy\DeployConfig;
use Igne\LaravelBootstrap\Deploy\ProvidesProjectCommands;
use Igne\LaravelBootstrap\Frontend\FrontendConfig;
use Igne\LaravelBootstrap\Frontend\PackageManagerSelector;
use Igne\LaravelBootstrap\Queue\QueueConfig;
use Illuminate\Contracts\Container\Container;

/**
 * Distils the package config and the host project's ProvidesProjectCommands
 * binding into a platform-agnostic deployment plan.
 */
final class DeploymentPlanner
{
    public function __construct(
        private readonly Container $container,
        private readonly DeployConfig $deploy,
        private readonly DatabaseConfig $database,
        private readonly FrontendConfig $frontend,
        private readonly QueueConfig $queue,
        private readonly PackageManagerSelector $packageManagers,
    ) {}

    public function plan(DeploymentEnvironment $environment, bool $zeroDowntime = true): DeploymentPlan
    {
        $projectCommands = $this->container->bound(ProvidesProjectCommands::class)
            ? $this->container->make(ProvidesProjectCommands::class)
            : null;

        return new DeploymentPlan(
            environment: $environment,
            migrate: $this->database->migrationsAuto,
            finalize: $this->deploy->finalize,
            beforeMigrations: $projectCommands?->beforeMigrations() ?? [],
            afterMigrations: $projectCommands?->afterMigrations() ?? [],
            frontend: $this->frontend->assets !== 'skip',
            packageManager: $this->packageManagers->selected(),
            restartQueues: $this->queue->enabled,
            zeroDowntime: $zeroDowntime,
        );
    }
}
