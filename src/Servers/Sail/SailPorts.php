<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Sail;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ReservedPort;
use Igne\LaravelBootUp\Process\ProcessRunner;

/**
 * Which host ports this project's compose file will publish.
 *
 * Compose itself is asked rather than the file parsed: it resolves
 * `${FORWARD_DB_PORT:-3306}` against the project's own .env, and it knows
 * about override files and services this package has never heard of.
 */
final class SailPorts
{
    /**
     * Sail's own forward variables, keyed by service and container port.
     * These are host-side conveniences -- the application talks to
     * `mysql:3306` over the compose network -- so moving one is safe, which
     * is what makes them remappable.
     *
     * Keyed by service too, not by container port alone: minio and rustfs
     * both publish 9000 behind different variables.
     *
     * @var array<string, array<int, string>>
     */
    private const array FORWARDS = [
        'mysql' => [3306 => 'FORWARD_DB_PORT'],
        'mariadb' => [3306 => 'FORWARD_DB_PORT'],
        'pgsql' => [5432 => 'FORWARD_DB_PORT'],
        'mongodb' => [27017 => 'FORWARD_MONGODB_PORT'],
        'redis' => [6379 => 'FORWARD_REDIS_PORT'],
        'valkey' => [6379 => 'FORWARD_VALKEY_PORT'],
        'memcached' => [11211 => 'FORWARD_MEMCACHED_PORT'],
        'meilisearch' => [7700 => 'FORWARD_MEILISEARCH_PORT'],
        'typesense' => [8108 => 'FORWARD_TYPESENSE_PORT'],
        'minio' => [9000 => 'FORWARD_MINIO_PORT', 8900 => 'FORWARD_MINIO_CONSOLE_PORT'],
        'rustfs' => [9000 => 'FORWARD_RUSTFS_PORT', 9001 => 'FORWARD_RUSTFS_CONSOLE_PORT'],
        'mailpit' => [1025 => 'FORWARD_MAILPIT_PORT', 8025 => 'FORWARD_MAILPIT_DASHBOARD_PORT'],
        'rabbitmq' => [5672 => 'FORWARD_RABBITMQ_PORT', 15672 => 'FORWARD_RABBITMQ_DASHBOARD_PORT'],
    ];

    /**
     * The application's own HTTP port. Movable, but only together with
     * APP_URL — the container keeps listening on 80 either way, so the port
     * the outside world uses is the one the application has to advertise.
     * Keyed by container port because the app service's name is
     * configurable.
     */
    private const int APP_TARGET = 80;

    /**
     * Ports the container listens on itself, so the host side cannot move
     * alone. Explained rather than offered.
     *
     * @var array<int, string>
     */
    private const array FIXED = [
        5173 => 'set VITE_PORT in your .env (Vite has to listen on it too)',
        6001 => 'set PUSHER_PORT in your .env (the container listens on it too)',
        9601 => 'set PUSHER_METRICS_PORT in your .env (the container listens on it too)',
    ];

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Sail $sail,
    ) {}

    /**
     * @return list<ReservedPort>
     */
    public function published(): array
    {
        $services = $this->services();
        $ports = [];

        foreach ($services as $service => $definition) {
            foreach ($this->portsOf($definition) as $mapping) {
                $port = $this->reserved((string) $service, $mapping);

                // Compose would refuse a duplicate host port itself; keeping
                // the first keeps the report free of repeats either way.
                if ($port !== null && ! isset($ports[$port->port])) {
                    $ports[$port->port] = $port;
                }
            }
        }

        return array_values($ports);
    }

    /**
     * The resolved compose services, or [] whenever that cannot be read: a
     * pre-flight check has no business failing a boot, and an unreadable
     * config means "do not check", not "nothing to check".
     *
     * @return array<array-key, mixed>
     */
    private function services(): array
    {
        if (! $this->sail->isConfigured()) {
            return [];
        }

        // SAIL_SKIP_CHECKS keeps sail's wrapper from running its own
        // pre-flight, which shuts down exited containers -- a read must not
        // do that. Only stdout is parsed: compose writes its
        // "variable is not set" warnings to stderr.
        $result = $this->runner->runSilently(
            CommandLine::make('./vendor/bin/sail config --format json')
                ->withEnv(['SAIL_SKIP_CHECKS' => '1']),
        );

        if (! $result->successful()) {
            return [];
        }

        $config = json_decode($result->output(), true);

        if (! \is_array($config) || ! \is_array($config['services'] ?? null)) {
            return [];
        }

        return $config['services'];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function portsOf(mixed $definition): array
    {
        if (! \is_array($definition) || ! \is_array($definition['ports'] ?? null)) {
            return [];
        }

        return array_values(array_filter($definition['ports'], \is_array(...)));
    }

    /**
     * @param  array<array-key, mixed>  $mapping
     */
    private function reserved(string $service, array $mapping): ?ReservedPort
    {
        $published = (string) ($mapping['published'] ?? '');
        $target = (int) ($mapping['target'] ?? 0);

        // A UDP clash is invisible to a TCP bind probe, and a published
        // range ("8000-8005") is not a port this can reason about.
        if (($mapping['protocol'] ?? 'tcp') !== 'tcp' || ! ctype_digit($published)) {
            return null;
        }

        if ($target === self::APP_TARGET) {
            return new ReservedPort(
                port: (int) $published,
                purpose: $service,
                envKey: 'APP_PORT',
                urlKey: 'APP_URL',
            );
        }

        $envKey = self::FORWARDS[$service][$target] ?? null;

        return new ReservedPort(
            port: (int) $published,
            purpose: $service,
            envKey: $envKey,
            fix: $envKey === null
                ? self::FIXED[$target] ?? "publish a different host port for {$service} in your compose file"
                : null,
        );
    }
}
