<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Tests\Feature\Console\Fixtures;

use Igne\LaravelBootstrap\Pipelines\PipelineGenerator;
use Igne\LaravelBootstrap\Pipelines\PipelinePlan;

/**
 * A project-registered custom git provider, as the extension API allows.
 */
final class StaticPipelineGenerator implements PipelineGenerator
{
    public function key(): string
    {
        return 'static';
    }

    public function label(): string
    {
        return 'Static Provider';
    }

    public function path(): string
    {
        return 'static-pipeline.yml';
    }

    public function generate(PipelinePlan $plan): string
    {
        return "static-pipeline for php {$plan->phpVersion}\n";
    }

    public function instructions(PipelinePlan $plan): array
    {
        return ['Static provider instructions.'];
    }
}
