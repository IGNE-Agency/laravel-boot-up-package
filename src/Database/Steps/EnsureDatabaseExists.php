<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database\Steps;

use Closure;
use Igne\LaravelBootUp\Database\DatabaseConfig;
use Igne\LaravelBootUp\Database\DatabaseCreator;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;
use Illuminate\Contracts\Config\Repository;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

/**
 * Creates the configured database when it does not exist yet. Skipped under
 * Sail — the container provisions the database from .env by itself.
 */
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
            note('Database creation is disabled in configuration — skipping.');

            return $next($context);
        }

        if ($context->server?->key() === 'sail') {
            note('Sail provisions the database inside its containers — skipping creation.');

            return $next($context);
        }

        $default = (string) $this->laravelConfig->get('database.default');
        $connection = (array) $this->laravelConfig->get("database.connections.{$default}", []);
        $database = (string) ($connection['database'] ?? '');

        if ($this->creator->createDatabaseIfMissing($connection)) {
            info("Database [{$database}] created.");
        } else {
            note("Database [{$database}] already exists.");
        }

        return $next($context);
    }
}
