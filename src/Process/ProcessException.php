<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Process;

use Igne\LaravelBootstrap\Support\BootstrapException;

final class ProcessException extends BootstrapException
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
