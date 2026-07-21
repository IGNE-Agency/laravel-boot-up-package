<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

use Igne\LaravelBootUp\Servers\Artisan\ArtisanServer;
use Igne\LaravelBootUp\Servers\Herd\HerdServer;
use Igne\LaravelBootUp\Servers\Sail\SailServer;
use Illuminate\Contracts\Config\Repository;

final readonly class ServersConfig
{
    private const array BUILT_IN_DRIVERS = [
        'herd' => HerdServer::class,
        'sail' => SailServer::class,
        'laravel' => ArtisanServer::class,
    ];

    /**
     * @param  array<string, class-string<Server>>  $drivers  key => driver class; project entries win over built-ins
     * @param  string|null  $herdSite  fixed Herd site name; null prompts with the folder name as default
     */
    public function __construct(
        public ?string $default = null,
        public bool $prompt = true,
        public array $drivers = self::BUILT_IN_DRIVERS,
        public bool $promptStopServer = true,
        public bool $stopServerByDefault = false,
        public ?string $herdSite = null,
        public string $artisanHost = '127.0.0.1',
        public int $artisanPort = 8000,
        public int $herdHealthAttempts = 10,
        public int $herdHealthDelayMs = 500,
        public int $herdHealthTimeoutSeconds = 5,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        $default = $config->get('boot-up.server.default');
        $herdSite = $config->get('boot-up.server.herd.site');

        return new self(
            default: $default === null ? null : (string) $default,
            prompt: (bool) $config->get('boot-up.server.prompt', true),
            drivers: array_merge(self::BUILT_IN_DRIVERS, (array) $config->get('boot-up.server.drivers', [])),
            promptStopServer: (bool) $config->get('boot-up.shutdown.prompt_stop_server', true),
            stopServerByDefault: (bool) $config->get('boot-up.shutdown.stop_server_by_default', false),
            herdSite: $herdSite === null ? null : (string) $herdSite,
            artisanHost: (string) $config->get('boot-up.server.artisan.host', '127.0.0.1'),
            artisanPort: (int) $config->get('boot-up.server.artisan.port', 8000),
            herdHealthAttempts: (int) $config->get('boot-up.server.herd.health.attempts', 10),
            herdHealthDelayMs: (int) $config->get('boot-up.server.herd.health.delay_ms', 500),
            herdHealthTimeoutSeconds: (int) $config->get('boot-up.server.herd.health.timeout_seconds', 5),
        );
    }
}
