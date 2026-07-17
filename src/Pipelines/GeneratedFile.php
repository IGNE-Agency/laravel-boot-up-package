<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Pipelines;

/**
 * One file a pipeline generator wants written into the host repository.
 */
final readonly class GeneratedFile
{
    public function __construct(
        public string $path,
        public string $contents,
        public bool $executable = false,
    ) {}
}
