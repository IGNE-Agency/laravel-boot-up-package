<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Pipelines;

use Igne\LaravelBootstrap\Deploy\Scripts\DeploymentPlan;

/**
 * Everything a pipeline generator needs to render a CI/CD pipeline,
 * distilled from this package's config and the host project's composer.json.
 */
final readonly class PipelinePlan
{
    /**
     * @param  array<string, string>  $branchHooks  git branch => deploy-hook secret/variable name
     */
    public function __construct(
        public DeploymentPlan $deployment,
        public bool $nova,
        public string $phpVersion,
        public array $branchHooks,
        public string $envFile = '.env.pipeline',
    ) {}
}
