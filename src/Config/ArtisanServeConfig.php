<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Concerns\ValidatesConfig;
use Illuminate\Contracts\Config\Repository;

/**
 * Where `php artisan serve` binds. Named after ArtisanServer, the driver it
 * configures — not after the artisan commands in Console.
 */
final readonly class ArtisanServeConfig
{
    use ValidatesConfig;

    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 8000,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            host: (string) $config->get('boot-up.artisan.host', '127.0.0.1'),
            port: self::intWithinRange($config, 'boot-up.artisan.port', 8000, 1, 65535),
        );
    }
}
