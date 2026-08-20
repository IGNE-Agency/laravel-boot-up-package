<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Pipelines;

use Igne\LaravelBootUp\Config\PipelineConfig;
use Igne\LaravelBootUp\Data\PipelinePlan;
use Igne\LaravelBootUp\Deploy\Scripts\DeploymentPlanner;
use Igne\LaravelBootUp\Enums\DeployHookHost;
use Igne\LaravelBootUp\Enums\DeploymentEnvironment;

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

    public function plan(DeployHookHost $host): PipelinePlan
    {
        $nova = $this->composerJson->requires('laravel/nova');

        return new PipelinePlan(
            // CI needs dev dependencies (the test framework) and must not
            // `artisan optimize` — exactly the DEVELOPMENT plan semantics.
            deployment: $this->deployments->plan(environment: DeploymentEnvironment::Development),
            nova: $nova,
            // COMPOSER_AUTH is offered whenever config opts in; absent an
            // explicit setting it defaults to on for Nova projects (the one
            // private dependency we can detect) and off otherwise.
            composerAuth: $this->config->composerAuth ?? $nova,
            pint: $this->composerJson->requires('laravel/pint') || $this->composerJson->requiresDev('laravel/pint'),
            phpVersion: $this->composerJson->phpVersion(),
            branchEnvironments: $this->config->branchEnvironments,
            host: $host,
        );
    }
}
