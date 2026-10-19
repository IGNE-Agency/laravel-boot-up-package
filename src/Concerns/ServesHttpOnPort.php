<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

/**
 * The AdvertisesUrl rule shared by every server that answers plain HTTP on
 * one host port: Sail publishing the application container's port 80,
 * `php artisan serve` binding a port itself.
 *
 * The hostname is deliberately no part of it. Whatever resolves to this
 * machine lands on that port — a Herd .test domain included — so there is
 * nothing to gain by insisting on `localhost`, and a project that reaches
 * its application through a name it likes keeps it.
 *
 * What does settle it: the port has to be the one being listened on, and
 * the scheme has to be a spelled-out http. Nothing here terminates TLS, so
 * an https URL reaches something else or nothing at all, and a URL naming
 * no scheme is not one the application can advertise.
 */
trait ServesHttpOnPort
{
    /** The port a URL means when it names none. */
    private const int HTTP_PORT = 80;

    private function urlReachesPort(string $url, int $port): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return false;
        }

        return ($parts['scheme'] ?? '') === 'http'
            && ($parts['port'] ?? self::HTTP_PORT) === $port;
    }

    /**
     * The same URL, with the port left off when it is the one a URL already
     * means — `http://localhost` rather than `http://localhost:80`.
     */
    private function httpUrlOn(string $host, int $port): string
    {
        return $port === self::HTTP_PORT ? "http://{$host}" : "http://{$host}:{$port}";
    }
}
