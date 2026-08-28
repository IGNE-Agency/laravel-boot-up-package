<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Exceptions;

final class ProcessException extends BootUpException
{
    public static function pidNotCaptured(string $label): self
    {
        return new self("Could not capture the process ID for background process [{$label}].");
    }
}
