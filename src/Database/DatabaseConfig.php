<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Database;

use Illuminate\Contracts\Config\Repository;

final readonly class DatabaseConfig
{
    public function __construct(
        public bool $create = true,
        public bool $promptMissingCredentials = true,
        public bool $migrationsAuto = true,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            create: (bool) $config->get('bootstrap.database.create', true),
            promptMissingCredentials: (bool) $config->get('bootstrap.database.prompt_missing_credentials', true),
            migrationsAuto: (bool) $config->get('bootstrap.migrations.auto', true),
        );
    }
}
