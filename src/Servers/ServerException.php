<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Servers;

use Igne\LaravelBootstrap\Support\BootstrapException;

final class ServerException extends BootstrapException
{
    public static function unknownServer(string $key): self
    {
        return new self("Unknown development server [{$key}]. Register it under bootstrap.server.drivers.");
    }

    public static function startFailed(string $label, string $reason): self
    {
        return new self("{$label} failed to start: {$reason}");
    }

    public static function dockerUnavailable(): self
    {
        return new self('Docker did not become available in time. Start Docker manually and try again.');
    }
}
