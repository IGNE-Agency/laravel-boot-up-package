<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Console\Fixtures;

use Igne\LaravelBootUp\Pipelines\GeneratedFile;
use Igne\LaravelBootUp\Pipelines\PipelineGenerator;
use Igne\LaravelBootUp\Pipelines\PipelinePlan;

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

    public function files(PipelinePlan $plan): array
    {
        return [new GeneratedFile('static-pipeline.yml', "static-pipeline for php {$plan->phpVersion}\n")];
    }

    public function secrets(PipelinePlan $plan): array
    {
        return [];
    }

    public function instructions(PipelinePlan $plan): array
    {
        return ['Static provider instructions.'];
    }
}
