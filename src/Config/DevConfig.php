<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Illuminate\Contracts\Config\Repository;

/**
 * Settings for the processes the dev command runs alongside the server.
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
