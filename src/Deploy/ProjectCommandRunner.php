<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy;

use Igne\LaravelBootUp\Contracts\ProvidesProjectCommands;
use Igne\LaravelBootUp\Data\ProjectCommand;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ShellCommand;
use Igne\LaravelBootUp\Exceptions\DeployException;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Illuminate\Contracts\Container\Container;
use Illuminate\Process\Exceptions\ProcessFailedException;
use InvalidArgumentException;

/**
 * Runs the host application's project commands for a deploy phase. The
 * provider is resolved lazily so projects that never bind
 * ProvidesProjectCommands pay nothing.
 */
final class ProjectCommandRunner
{
    public function __construct(
        private readonly Container $container,
        private readonly ProcessRunner $processes,
        private readonly CommandRewriter $rewriter,
        private readonly PackageManagerSelector $packageManagers,
    ) {}

    /**
     * @param  string  $phase  'before-deploy', 'before' / 'after' (migrations) or 'after-deploy'
     */
    public function run(string $phase, ServeContext $context): void
    {
        if (! $this->container->bound(ProvidesProjectCommands::class)) {
            return;
        }

        $provider = $this->container->make(ProvidesProjectCommands::class);

        $commands = match ($phase) {
            'before-deploy' => $provider->beforeDeploy(),
            'before' => $provider->beforeMigrations(),
            'after' => $provider->afterMigrations(),
            'after-deploy' => $provider->afterDeploy(),
            default => throw new InvalidArgumentException("Unknown project command phase [{$phase}]; expected 'before-deploy', 'before', 'after' or 'after-deploy'."),
        };

        foreach ($commands as $command) {
            $this->execute($command, $context);
        }
    }

    private function execute(ProjectCommand $command, ServeContext $context): void
    {
        if ($command->description !== null) {
            terminal()->info($command->description);
        }

        $shell = $this->rewriter->rewrite(
            ShellCommand::make($this->tokensFor($command)),
            $context->commandRewrites(),
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
    private function tokensFor(ProjectCommand $command): array
    {
        $line = $command->shellLine('php artisan', 'composer', $this->packageManagers->selected()->binary());

        return preg_split('/\s+/', trim($line)) ?: [];
    }
}
