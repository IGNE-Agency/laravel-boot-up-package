<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Pipelines;

use Igne\LaravelBootUp\Data\PipelineFile;
use Igne\LaravelBootUp\Data\PipelineJobStep;
use Igne\LaravelBootUp\Data\PipelinePlan;

/**
 * The validated, provider-aware set of extra steps and files a project has
 * configured. Carried on the PipelinePlan so generators can splice matching
 * steps into their jobs and the command can emit the extra files.
 */
final readonly class PipelineExtensions
{
    /**
     * @param  list<PipelineJobStep>  $steps
     * @param  list<PipelineFile>  $files
     */
    public function __construct(
        public array $steps = [],
        public array $files = [],
    ) {}

    /**
     * The steps to render at one job anchor and position for a provider, in
     * configured order.
     *
     * @return list<PipelineJobStep>
     */
    public function stepsFor(string $provider, string $job, string $position): array
    {
        return array_values(array_filter(
            $this->steps,
            fn (PipelineJobStep $step): bool => ($step->provider === null || $step->provider === $provider)
                && $step->job === $job
                && $step->position === $position,
        ));
    }

    /**
     * The extra files to write for a provider (unscoped files apply to all).
     *
     * @return list<PipelineFile>
     */
    public function filesFor(string $provider): array
    {
        return array_values(array_filter(
            $this->files,
            fn (PipelineFile $file): bool => $file->provider === null || $file->provider === $provider,
        ));
    }
}
