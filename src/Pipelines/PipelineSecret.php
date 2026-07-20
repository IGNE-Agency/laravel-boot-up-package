<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Pipelines;

/**
 * One secret/variable the generated pipeline expects, with the
 * provider-specific place to add it and where its value comes from.
 * Rendered as a row of the app:pipeline instructions table.
 */
final readonly class PipelineSecret
{
    public function __construct(
        public string $name,
        public string $location,
        public string $value,
        public string $purpose,
    ) {}
}
