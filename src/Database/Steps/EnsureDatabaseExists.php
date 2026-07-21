<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database\Steps;

use Closure;
use Igne\LaravelBootUp\Database\DatabaseConfig;
use Igne\LaravelBootUp\Database\DatabaseCreator;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;
use Illuminate\Contracts\Config\Repository;

/**
 * Creates the configured database when it does not exist yet. Skipped for
 * servers that provision the database themselves (e.g. Sail's containers).
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
            terminal()->note('Database creation is disabled in configuration — skipping.');

            return $next($context);
        }

        if ($context->server?->providesDatabase() === true) {
            terminal()->note("{$context->server->label()} provisions the database itself — skipping creation.");

            return $next($context);
        }

        $default = (string) $this->laravelConfig->get('database.default');
        $connection = (array) $this->laravelConfig->get("database.connections.{$default}", []);
        $database = (string) ($connection['database'] ?? '');

        if ($this->creator->createDatabaseIfMissing($connection)) {
            terminal()->success("Database [{$database}] created.");
        } else {
            terminal()->note("Database [{$database}] already exists.");
        }

        return $next($context);
    }
}
