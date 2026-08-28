<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Exceptions;

final class FrontendException extends BootUpException
{
    public static function installFailed(string $manager, string $reason): self
    {
        return new self("Installing frontend dependencies with [{$manager}] failed: {$reason}");
    }
}
