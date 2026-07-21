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
            $this->pint,
            $this->phpVersion,
            $this->branchEnvironments,
            $this->envFile,
            $this->host,
            $extensions,
        );
    }

    /**
     * The environment worth an approval gate — the last mapping in
     * branchEnvironments, which is the most production-like by the map's
     * dev → prod convention. Null when no branches are mapped.
     */
    public function approvalEnvironment(): ?string
    {
        $key = array_key_last($this->branchEnvironments);

        return $key === null ? null : $this->branchEnvironments[$key];
    }
}
