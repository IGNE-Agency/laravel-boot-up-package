<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Config\DatabaseConfig;
use Igne\LaravelBootUp\Contracts\ProvidesDatabase;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\DatabaseConnection;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Database\DatabaseCreator;
use Igne\LaravelBootUp\Enums\ServeStage;
use Illuminate\Contracts\Config\Repository;

/**
 * Creates the configured database when it does not exist yet. Skipped for
 * servers that provision the database themselves (e.g. Sail's containers).
 */
#[Stage(ServeStage::Database)]
#[Group('database')]
final class EnsureDatabaseExists implements Step
{
    public function __construct(
        private readonly DatabaseConfig $config,
        private readonly DatabaseCreator $creator,
        private readonly Repository $laravelConfig,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if (! $this->config->create) {
            terminal()->note('Database creation is disabled in configuration — skipping.');

            return $next($context);
        }

        if ($context->server instanceof ProvidesDatabase) {
            terminal()->note("{$context->server->label()} provisions the database itself — skipping creation.");

            return $next($context);
        }

        $default = (string) $this->laravelConfig->get('database.default');
        $connection = DatabaseConnection::fromArray(
            (array) $this->laravelConfig->get("database.connections.{$default}", []),
        );

        if ($this->creator->createDatabaseIfMissing($connection)) {
            terminal()->success("Database [{$connection->database}] created.");
        } else {
            terminal()->note("Database [{$connection->database}] already exists.");
        }

        return $next($context);
    }
}
