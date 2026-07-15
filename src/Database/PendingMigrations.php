<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Database;

use Illuminate\Database\Migrations\Migrator;

/**
 * Diffs the migration files on disk against the migrations that already ran,
 * using the framework's own Migrator — no SHOW TABLES, driver-agnostic.
 */
final class PendingMigrations
{
    public function __construct(
        private readonly Migrator $migrator,
    ) {}

    /**
     * @return list<string> migration names that have not run yet
     */
    public function pending(): array
    {
        $files = $this->migrator->getMigrationFiles([
            ...$this->migrator->paths(),
            database_path('migrations'),
        ]);

        $ran = $this->migrator->repositoryExists()
            ? $this->migrator->getRepository()->getRan()
            : [];

        return array_values(array_diff(array_keys($files), $ran));
    }

    public function count(): int
    {
        return \count($this->pending());
    }
}
