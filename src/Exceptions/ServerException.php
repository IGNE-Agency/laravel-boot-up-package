<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Exceptions;

use Igne\LaravelBootUp\Data\PortConflict;

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
            .'(or restart Herd from its menu-bar app) and run php artisan dev again.'
        );
    }

    public static function dockerUnavailable(): self
    {
        return new self('Docker did not become available in time. Start Docker manually and try again.');
    }

    /**
     * Every clashing port in one message: a boot that reports only the first
     * one sends the user round the loop again for the second.
     *
     * @param  list<PortConflict>  $conflicts
     */
    public static function portsUnavailable(string $label, array $conflicts): self
    {
        $count = \count($conflicts);
        $lines = array_map(
            static fn (PortConflict $conflict): string => "  • {$conflict->describe()}",
            $conflicts,
        );

        return new self(implode(PHP_EOL, [
            $count === 1
                ? "{$label} cannot start — a host port it needs is already in use:"
                : "{$label} cannot start — {$count} of the host ports it needs are already in use:",
            ...$lines,
            'Free them or move them, then run php artisan app:up again.',
        ]));
    }

    public static function dockerRegistryUnreachable(): self
    {
        return new self(
            'Docker could not reach its image registry — a Docker/network problem, not a project problem. '
            .'Check your internet connection and VPN, restart Docker Desktop, and if it persists point Docker '
            ."Desktop's DNS at a public resolver (Settings → Resources → Network), then run php artisan dev again."
        );
    }
}
