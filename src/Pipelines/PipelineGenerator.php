<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Pipelines;

/**
 * Renders everything a CI/CD provider needs from a pipeline plan. Register
 * custom generators under boot-up.pipeline.generators.
 */
interface PipelineGenerator
{
    /**
     * The provider key used on the command line, e.g. "github".
     */
    public function key(): string;

    /**
     * The human-readable provider name, e.g. "GitHub Actions".
     */
    public function label(): string;

    /**
     * Every file this provider needs, repo-relative. The first entry should
     * be the provider's pipeline definition; shared scripts follow.
     *
     * @return list<GeneratedFile>
     */
    public function files(PipelinePlan $plan): array;

    /**
     * The secrets/variables the generated pipeline expects — each is a row
     * of the instructions table plus its own detail section with the
     * provider-specific place to add it and where the value comes from.
     *
     * @return list<PipelineSecret>
     */
    public function secrets(PipelinePlan $plan): array;

    /**
     * The "Next steps" list, printed after the secrets table and sections.
     *
     * @return list<string>
     */
    public function instructions(PipelinePlan $plan): array;
}
