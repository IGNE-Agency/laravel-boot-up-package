<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

use Igne\LaravelBootUp\Pipelines\PipelineExtensionValidator;

/**
 * A configured extra step injected into a generated pipeline job. Validated
 * and built by PipelineExtensionValidator from boot-up.pipeline.steps.
 */
final readonly class PipelineStep
{
    /**
     * @param  string  $job  the generator anchor to attach to (e.g. "test")
     * @param  string  $position  "before" or "after" the job's own step
     * @param  string|null  $provider  restrict to one provider key; null = all
     * @param  array<string, string>  $env  GitHub only — name => value
     */
    public function __construct(
        public string $id,
        public string $job,
        public string $position,
        public string $name,
        public string $run,
        public ?string $provider = null,
        public array $env = [],
    ) {}
}
