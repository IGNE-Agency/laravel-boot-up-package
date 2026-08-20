<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy;

use Igne\LaravelBootUp\Concerns\RunsThroughServer;
use Igne\LaravelBootUp\Contracts\ProvidesDeployTasks;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\DeployTask;
use Igne\LaravelBootUp\Enums\DeployPhase;
use Igne\LaravelBootUp\Exceptions\DeployException;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Illuminate\Contracts\Container\Container;
use Illuminate\Process\Exceptions\ProcessFailedException;

/**
 * Runs the host application's project commands for a deploy phase. The
 * provider is resolved lazily so projects that never bind
 * ProvidesDeployTasks pay nothing.
 */
final class DeployTaskRunner
{
    use RunsThroughServer;

    public function __construct(
        private readonly Container $container,
        private readonly ProcessRunner $runner,
        private readonly CommandRewriter $rewriter,
        private readonly PackageManagerSelector $packageManagers,
    ) {}

    public function run(DeployPhase $phase, BootContext $context): void
    {
        if (! $this->container->bound(ProvidesDeployTasks::class)) {
            return;
        }

        $provider = $this->container->make(ProvidesDeployTasks::class);

        $commands = match ($phase) {
            DeployPhase::BeforeDeploy => $provider->beforeDeploy(),
            DeployPhase::Before => $provider->beforeMigrations(),
            DeployPhase::After => $provider->afterMigrations(),
            DeployPhase::AfterDeploy => $provider->afterDeploy(),
        };

        foreach ($commands as $command) {
            $this->execute($command, $context);
        }
    }

    private function execute(DeployTask $command, BootContext $context): void
    {
        if ($command->description !== null) {
            terminal()->info($command->description);
        }

        try {
            $this->runThroughServer($context, CommandLine::make($this->tokensFor($command)));
        } catch (ProcessFailedException $exception) {
            throw DeployException::commandFailed($command, $exception->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private function tokensFor(DeployTask $command): array
    {
        $line = $command->shellLine('php artisan', 'composer', $this->packageManagers->selected()->binary());

        return preg_split('/\s+/', trim($line)) ?: [];
    }
}
