<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Exceptions;

final class PipelineException extends BootUpException
{
    public static function step(string $id, string $problem): self
    {
        return new self("Invalid pipeline step [{$id}]: {$problem}");
    }

    public static function duplicateStepId(string $id): self
    {
        return new self("Duplicate pipeline step id [{$id}]; every boot-up.pipeline.steps entry needs a unique id.");
    }

    public static function file(string $path, string $problem): self
    {
        return new self("Invalid pipeline file [{$path}]: {$problem}");
    }
}
