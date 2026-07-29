<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Exceptions;

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

    public static function unreachable(string $url, int $attempts): self
    {
        return new self(
            "Laravel Herd did not become reachable at {$url} after {$attempts} attempt(s). "
            .'Nginx may be unhealthy — inspect the services with `herd services:list`, then try `herd restart` '
            .'(or restart Herd from its menu-bar app) and run app:serve again.'
        );
    }

    public static function dockerUnavailable(): self
    {
        return new self('Docker did not become available in time. Start Docker manually and try again.');
    }

    public static function dockerRegistryUnreachable(): self
    {
        return new self(
            'Docker could not reach its image registry — a Docker/network problem, not a project problem. '
            .'Check your internet connection and VPN, restart Docker Desktop, and if it persists point Docker '
            ."Desktop's DNS at a public resolver (Settings → Resources → Network), then run app:serve again."
        );
    }
}
