<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * One connection attempt against the configured database — nothing else.
 *
 * Connectivity is all the boot needs to know before it migrates, and
 * anything that reads schema answers a different question: `migrate:status`
 * fails on a connected database that has no migrations table yet, which is
 * exactly the state a database container is in the moment it is created.
 *
 * Deliberately a plain service rather than a step, because both halves of
 * the verification run it: the host-side check calls it directly, and the
 * container-side check reaches it through app:db-ping. Same code, same
 * verdict, wherever the database lives.
 */
final class DatabaseProbe
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Repository $config,
    ) {}

    /**
     * Null when the database accepted the connection; otherwise the failure
     * message, for DatabaseFailureDetector to classify.
     */
    public function attempt(): ?string
    {
        $connection = $this->connectionName();

        try {
            // Purged so a retry opens a fresh PDO instead of reusing the
            // connection whose handshake just failed.
            $this->database->purge($connection);
            $this->database->connection($connection)->getPdo();

            return null;
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }
    }

    public function connectionName(): string
    {
        return (string) $this->config->get('database.default');
    }

    /**
     * The database the default connection points at — named in failures
     * that are about the database rather than the connection to it.
     */
    public function databaseName(): string
    {
        return (string) $this->config->get("database.connections.{$this->connectionName()}.database", '');
    }

    /**
     * The driver behind the default connection, falling back to the
     * connection name — which is what Laravel's own conventions name it.
     */
    public function driver(): string
    {
        $connection = $this->connectionName();

        return (string) $this->config->get("database.connections.{$connection}.driver", $connection);
    }
}
