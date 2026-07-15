<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

use Igne\LaravelBootUp\Support\BootUpException;

final class ServerException extends BootUpException
{
    public static function unknownServer(string $key): self
    {
        return new self("Unknown development server [{$key}]. Register it under boot-up.server.drivers.");
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
