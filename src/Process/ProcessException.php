<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process;

use Igne\LaravelBootUp\Support\BootUpException;

final class ProcessException extends BootUpException
{
    public static function pidNotCaptured(string $label): self
    {
        return new self("Could not capture the process ID for background process [{$label}].");
    }

    public static function terminalPidNotCaptured(string $label): self
    {
        return new self("The terminal window for [{$label}] did not report its process ID in time.");
    }
}
