<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

use Igne\LaravelBootUp\Data\GeneratedFile;
use Igne\LaravelBootUp\Data\PipelinePlan;
use Igne\LaravelBootUp\Data\PipelineSecret;

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
     * The job/step names project-configured extra steps may attach to (see
     * boot-up.pipeline.steps), for this plan — e.g. "lint" only exists with
     * Pint, "deploy" only with a deploy host. Used to validate configuration.
     *
     * @return list<string>
     */
    public function anchors(PipelinePlan $plan): array;

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
     * Informational "Good to know" notes — things that are true about the
     * generated pipeline but are NOT actions the user must take. Printed as an
     * unnumbered block before the Next steps.
     *
     * @return list<string>
     */
    public function notes(PipelinePlan $plan): array;

    /**
     * The actionable "Next steps" list, printed after the notes block — only
     * things the user must actually do to finish wiring the pipeline up.
     *
     * @return list<string>
     */
    public function instructions(PipelinePlan $plan): array;
}
