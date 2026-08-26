<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Servers\Artisan\ArtisanServer;
use Igne\LaravelBootUp\Servers\Herd\HerdServer;
use Igne\LaravelBootUp\Servers\Sail\SailServer;
use Illuminate\Contracts\Config\Repository;

/**
 * Which development server drives the boot. Named DevServerConfig to stay
 * unmistakable beside SetupConfig and DevConfig (the commands themselves).
 */
final readonly class DevServerConfig
{
    private const array BUILT_IN_DRIVERS = [
        'herd' => HerdServer::class,
        'sail' => SailServer::class,
        'artisan' => ArtisanServer::class,
    ];

    /**
     * @param  array<string, class-string<Server>>  $drivers  key => driver class; project entries win over built-ins
     * @param  bool  $checkPorts  probe the ports a driver publishes before starting it
     */
    public function __construct(
        public ?string $default = null,
        public bool $prompt = true,
        public array $drivers = self::BUILT_IN_DRIVERS,
        public bool $checkPorts = true,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        $default = $config->get('boot-up.server.default');

        return new self(
            default: $default === null ? null : (string) $default,
            prompt: (bool) $config->get('boot-up.server.prompt', true),
            drivers: array_merge(self::BUILT_IN_DRIVERS, (array) $config->get('boot-up.server.drivers', [])),
            checkPorts: (bool) $config->get('boot-up.server.check_ports', true),
        );
    }
}
