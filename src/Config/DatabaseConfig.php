<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Concerns\ValidatesConfig;
use Illuminate\Contracts\Config\Repository;

final readonly class DatabaseConfig
{
    use ValidatesConfig;

    public function __construct(
        public bool $create = true,
        public bool $promptMissingCredentials = true,
        public bool $reconcileServerCredentials = true,
        public int $readyTimeoutSeconds = 60,
        public bool $migrationsAuto = true,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            create: (bool) $config->get('boot-up.database.create', true),
            promptMissingCredentials: (bool) $config->get('boot-up.database.prompt_missing_credentials', true),
            reconcileServerCredentials: (bool) $config->get('boot-up.database.reconcile_credentials', true),
            readyTimeoutSeconds: self::intAtLeast($config, 'boot-up.database.ready_timeout_seconds', 60, 0),
            migrationsAuto: (bool) $config->get('boot-up.database.migrations.auto', true),
        );
    }
}
