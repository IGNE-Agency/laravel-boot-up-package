<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

use Igne\LaravelBootUp\Pipelines\PipelineExtensionValidator;

/**
 * An extra file emitted verbatim alongside the generated pipeline. Validated
 * and built by PipelineExtensionValidator from boot-up.pipeline.files; its
 * contents come from an inline "contents" or a "stub" file read as-is.
 */
final readonly class PipelineFile
{
    /**
     * @param  string|null  $provider  restrict to one provider key; null = all
     */
    public function __construct(
        public string $path,
        public string $contents,
        public bool $executable = false,
        public ?string $provider = null,
    ) {}
}
