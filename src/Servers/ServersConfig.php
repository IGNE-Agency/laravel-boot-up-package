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
     */
    public function __construct(
        public ?string $default = null,
        public bool $prompt = true,
        public array $drivers = self::BUILT_IN_DRIVERS,
        public bool $promptStopServer = true,
        public bool $stopServerByDefault = false,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        $default = $config->get('boot-up.server.default');

        return new self(
            default: $default === null ? null : (string) $default,
            prompt: (bool) $config->get('boot-up.server.prompt', true),
            drivers: array_merge(self::BUILT_IN_DRIVERS, (array) $config->get('boot-up.server.drivers', [])),
            promptStopServer: (bool) $config->get('boot-up.shutdown.prompt_stop_server', true),
            stopServerByDefault: (bool) $config->get('boot-up.shutdown.stop_server_by_default', false),
        );
    }
}
