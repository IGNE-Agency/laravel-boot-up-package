<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Workers\Steps;

use Closure;
use Igne\LaravelBootUp\Config\WorkersConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\WorkerDefinition;
use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Serve\WorkerLauncher;

/**
 * Starts a tracked Horizon supervisor when laravel/horizon is a project
 * dependency. Detect-and-skip: projects without Horizon never notice
 * this step.
 */
final class StartHorizon implements Step
{
    private const LABEL = 'horizon';

    public function __construct(
        private readonly WorkersConfig $config,
        private readonly ComposerJson $composerJson,
        private readonly WorkerLauncher $launcher,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if ($this->config->horizonEnabled && $this->composerJson->requires('laravel/horizon')) {
            $this->launcher->launch($this->worker(), $context);
        }

        return $next($context);
    }

    private function worker(): WorkerDefinition
    {
        return new WorkerDefinition(
            label: self::LABEL,
            name: 'Horizon',
            tokens: ['php', 'artisan', 'horizon'],
            runIn: $this->config->horizonRunIn,
        );
    }
}
