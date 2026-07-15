<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Frontend;

use Igne\LaravelBootstrap\Support\BootstrapException;

final class FrontendException extends BootstrapException
{
    public static function installFailed(string $manager, string $reason): self
    {
        return new self("Installing frontend dependencies with [{$manager}] failed: {$reason}");
    }
}
