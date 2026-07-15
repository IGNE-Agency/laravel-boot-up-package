<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Pipelines;

use Igne\LaravelBootstrap\Deploy\Scripts\DeploymentEnvironment;
use Igne\LaravelBootstrap\Deploy\Scripts\DeploymentPlanner;

/**
 * Distils the package config and the host project's composer.json into a
 * provider-agnostic pipeline plan.
 */
final class PipelinePlanner
{
    public function __construct(
        private readonly DeploymentPlanner $deployments,
        private readonly ComposerJson $composerJson,
        private readonly PipelineConfig $config,
    ) {}

    public function plan(): PipelinePlan
    {
        return new PipelinePlan(
            // CI needs dev dependencies (the test framework) and must not
            // `artisan optimize` — exactly the DEVELOPMENT plan semantics.
            deployment: $this->deployments->plan(environment: DeploymentEnvironment::DEVELOPMENT),
            nova: $this->composerJson->requires('laravel/nova'),
            phpVersion: $this->composerJson->phpVersion(),
            branchHooks: $this->config->branchHooks,
        );
    }
}
