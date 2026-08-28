<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy\Scripts;

use Igne\LaravelBootUp\Boot\HorizonPresence;
use Igne\LaravelBootUp\Config\DatabaseConfig;
use Igne\LaravelBootUp\Config\DeployConfig;
use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Config\QueueConfig;
use Igne\LaravelBootUp\Config\ReverbConfig;
use Igne\LaravelBootUp\Contracts\ProvidesDeployTasks;
use Igne\LaravelBootUp\Data\DeploymentPlan;
use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Enums\BuiltInProcess;
use Igne\LaravelBootUp\Enums\DeploymentEnvironment;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Illuminate\Contracts\Container\Container;

/**
 * Distils the package config and the host project's ProvidesDeployTasks
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
        private readonly ReverbConfig $reverb,
        private readonly HorizonPresence $horizon,
        private readonly ComposerJson $composerJson,
        private readonly PackageManagerSelector $packageManagers,
    ) {}

    public function plan(DeploymentEnvironment $environment, bool $zeroDowntime = true): DeploymentPlan
    {
        $projectCommands = $this->container->bound(ProvidesDeployTasks::class)
            ? $this->container->make(ProvidesDeployTasks::class)
            : null;

        return new DeploymentPlan(
            environment: $environment,
            migrate: $this->database->migrationsAuto,
            finalize: $this->deploy->finalize,
            beforeMigrations: $projectCommands?->beforeMigrations() ?? [],
            afterMigrations: $projectCommands?->afterMigrations() ?? [],
            frontend: $this->frontend->assets !== AssetMode::Skip,
            packageManager: $this->packageManagers->selected(),
            restarts: $this->restarts(),
            zeroDowntime: $zeroDowntime,
            beforeDeploy: $projectCommands?->beforeDeploy() ?? [],
            afterDeploy: $projectCommands?->afterDeploy() ?? [],
        );
    }

    /**
     * The long-running services this project runs, so a deploy tells the
     * right ones to pick up the new code. Same detection the dev processes
     * use, in the same order — a project on Horizon must not be sent
     * queue:restart, which its supervised workers do not answer.
     *
     * The queue connection is deliberately not consulted: it comes from the
     * deploy target's environment, not from this machine's .env.
     *
     * @return list<BuiltInProcess>
     */
    private function restarts(): array
    {
        $horizon = $this->horizon->managesQueue();

        return array_values(array_filter(BuiltInProcess::cases(), fn (BuiltInProcess $process): bool => match ($process) {
            BuiltInProcess::Queue => $this->queue->enabled && ! $horizon,
            BuiltInProcess::Horizon => $horizon,
            BuiltInProcess::Reverb => $this->reverb->enabled && $this->composerJson->requires('laravel/reverb'),
            default => false,
        }));
    }
}
