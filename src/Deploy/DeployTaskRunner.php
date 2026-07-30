<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy;

use Igne\LaravelBootUp\Contracts\ProvidesDeployTasks;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\DeployTask;
use Igne\LaravelBootUp\Data\ServeContext;
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
    public function __construct(
        private readonly Container $container,
        private readonly ProcessRunner $processes,
        private readonly CommandRewriter $rewriter,
        private readonly PackageManagerSelector $packageManagers,
    ) {}

    public function run(DeployPhase $phase, ServeContext $context): void
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

    private function execute(DeployTask $command, ServeContext $context): void
    {
        if ($command->description !== null) {
            terminal()->info($command->description);
        }

        $shell = $this->rewriter->rewriteFor(
            $context,
            CommandLine::make($this->tokensFor($command)),
        );

        try {
            $this->processes->run($shell);
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
