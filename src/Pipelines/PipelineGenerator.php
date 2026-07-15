<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Pipelines;

interface PipelineGenerator
{
    public function key(): string;

    public function label(): string;

    /**
     * The repo-relative path the provider requires the pipeline file to live at.
     */
    public function path(): string;

    public function generate(PipelinePlan $plan): string;

    /**
     * Provider-specific follow-up instructions shown after generating.
     *
     * @return list<string>
     */
    public function instructions(PipelinePlan $plan): array;
}
