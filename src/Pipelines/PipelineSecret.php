<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Pipelines;

/**
 * One secret/variable the generated pipeline expects. The short fields form
 * a row of the generate:pipeline instructions table; the details are the long
 * guidance (exact settings path, where the value comes from) rendered as
 * that secret's own section below the table.
 */
final readonly class PipelineSecret
{
    /**
     * @param  list<string>  $details
     */
    public function __construct(
        public string $name,
        public string $location,
        public string $purpose,
        public array $details = [],
    ) {}
}
