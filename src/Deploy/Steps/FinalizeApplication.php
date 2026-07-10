<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Deploy\Steps;

use Closure;
use Igne\LaravelBootstrap\Deploy\DeployConfig;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\ShellCommand;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\Step;

use function Laravel\Prompts\info;

/**
 * Runs the configured bootstrap.deploy.finalize artisan commands host-side
 * (default: storage:link).
 */
final class FinalizeApplication implements Step
{
    public function __construct(
        private readonly DeployConfig $config,
        private readonly ProcessRunner $processes,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        foreach ($this->config->finalize as $command) {
            info("Running php artisan {$command}...");

            $this->processes->run(ShellCommand::make("php artisan {$command}"));
        }

        return $next($context);
    }
}
