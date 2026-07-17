<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Pipelines;

use Igne\LaravelBootUp\Deploy\Scripts\DeploymentPlan;

/**
 * Everything a pipeline generator needs to render a CI/CD pipeline,
 * distilled from this package's config and the host project's composer.json.
 */
final readonly class PipelinePlan
{
    /**
     * @param  array<string, string>  $branchEnvironments  git branch => deployment environment name
     */
    public function __construct(
        public DeploymentPlan $deployment,
        public bool $nova,
        public bool $pint,
        public string $phpVersion,
        public array $branchEnvironments,
        public string $envFile = '.env.pipeline',
    ) {}
}
