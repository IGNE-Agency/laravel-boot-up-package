<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

use Igne\LaravelBootUp\Data\DeploymentPlan;
use Igne\LaravelBootUp\Enums\DeployHookHost;
use Igne\LaravelBootUp\Pipelines\PipelineExtensions;

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
        public bool $composerAuth,
        public bool $pint,
        public string $phpVersion,
        public array $branchEnvironments,
        public string $envFile = '.env.pipeline',
        public DeployHookHost $host = DeployHookHost::WEBHOOK,
        public PipelineExtensions $extensions = new PipelineExtensions,
    ) {}

    /**
     * A copy of this plan carrying the validated project extensions.
     */
    public function withExtensions(PipelineExtensions $extensions): self
    {
        return new self(
            $this->deployment,
            $this->nova,
            $this->composerAuth,
            $this->pint,
            $this->phpVersion,
            $this->branchEnvironments,
            $this->envFile,
            $this->host,
            $extensions,
        );
    }
}
