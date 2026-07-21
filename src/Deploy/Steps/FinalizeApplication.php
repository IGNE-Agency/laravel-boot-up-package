<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy\Steps;

use Closure;
use Igne\LaravelBootUp\Deploy\DeployConfig;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;

/**
 * Runs the configured boot-up.deploy.finalize artisan commands host-side
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
            terminal()->info("Running php artisan {$command}...");

            $this->processes->run(ShellCommand::make("php artisan {$command}"));
        }

        return $next($context);
    }
}
