<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

use Igne\LaravelBootUp\Contracts\PipelineGenerator;

/**
 * The fixed surroundings of one pipeline validation pass: the chosen
 * generator, the plan its anchors are checked against, and every known
 * provider key for the optional per-step provider filter.
 */
final readonly class PipelineContext
{
    /**
     * @param  list<string>  $providers
     */
    public function __construct(
        public PipelineGenerator $generator,
        public PipelinePlan $plan,
        public array $providers,
    ) {}
}
