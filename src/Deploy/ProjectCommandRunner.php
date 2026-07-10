<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Deploy;

use Igne\LaravelBootstrap\Frontend\PackageManagerSelector;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\ShellCommand;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Servers\CommandRewriter;
use Illuminate\Contracts\Container\Container;
use Illuminate\Process\Exceptions\ProcessFailedException;
use InvalidArgumentException;

use function Laravel\Prompts\note;

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
     * @param  string  $phase  'before' or 'after' (migrations)
     */
    public function run(string $phase, ServeContext $context): void
    {
        if (! $this->container->bound(ProvidesProjectCommands::class)) {
            return;
        }

        $provider = $this->container->make(ProvidesProjectCommands::class);

        $commands = match ($phase) {
            'before' => $provider->beforeMigrations(),
            'after' => $provider->afterMigrations(),
            default => throw new InvalidArgumentException("Unknown project command phase [{$phase}]; expected 'before' or 'after'."),
        };

        foreach ($commands as $command) {
            $this->execute($command, $context);
        }
    }

    private function execute(ProjectCommand $command, ServeContext $context): void
    {
        if ($command->description !== null) {
            note($command->description);
        }

        $shell = $this->rewriter->rewrite(
            ShellCommand::make($this->tokensFor($command)),
            $context->server?->commandRewrites(),
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
        $parts = preg_split('/\s+/', trim($command->command)) ?: [];

        return match ($command->type) {
            ProjectCommandType::ARTISAN => ['php', 'artisan', ...$parts],
            ProjectCommandType::COMPOSER => ['composer', ...$parts],
            ProjectCommandType::PACKAGE_MANAGER => [$this->packageManagers->selected()->binary(), ...$parts],
        };
    }
}
