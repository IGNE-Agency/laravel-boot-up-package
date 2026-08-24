<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Illuminate\Contracts\Config\Repository;

/**
 * What `php artisan dev` runs beyond the processes it detects.
 */
final readonly class DevConfig
{
    public function __construct(
        public bool $logs = true,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            logs: (bool) $config->get('boot-up.dev.logs', true),
        );
    }
}
