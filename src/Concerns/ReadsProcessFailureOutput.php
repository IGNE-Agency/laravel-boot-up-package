<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

use Illuminate\Process\Exceptions\ProcessFailedException;

/**
 * Both channels of a failed process, for error classification — stderr
 * alone misses tools that report failures on stdout, and vice versa.
 */
trait ReadsProcessFailureOutput
{
    private function outputOf(ProcessFailedException $exception): string
    {
        return "{$exception->result->output()}\n{$exception->result->errorOutput()}";
    }
}
