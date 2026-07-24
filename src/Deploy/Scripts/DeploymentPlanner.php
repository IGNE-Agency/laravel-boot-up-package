<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy\Scripts;

use Igne\LaravelBootUp\Config\DatabaseConfig;
use Igne\LaravelBootUp\Config\DeployConfig;
use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Config\QueueConfig;
use Igne\LaravelBootUp\Contracts\ProvidesProjectCommands;
use Igne\LaravelBootUp\Data\DeploymentPlan;
use Igne\LaravelBootUp\Enums\DeploymentEnvironment;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
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
            beforeDeploy: $projectCommands?->beforeDeploy() ?? [],
            afterDeploy: $projectCommands?->afterDeploy() ?? [],
        );
    }
}
