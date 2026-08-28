<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database;

use Igne\LaravelBootUp\Data\DatabaseConnection;
use Igne\LaravelBootUp\Exceptions\DatabaseException;
use PDO;
use PDOException;

/**
 * Driver-agnostic database existence checks and creation. Server drivers
 * (mysql/pgsql/sqlsrv) connect via PDO without selecting a database — the
 * database may not exist yet; sqlite is a plain file on disk.
 */
final class DatabaseCreator
{
    private const array EXISTS_QUERIES = [
        'mysql' => 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
        'pgsql' => 'SELECT datname FROM pg_database WHERE datname = ?',
        'sqlsrv' => 'SELECT name FROM sys.databases WHERE name = ?',
    ];

    public function databaseExists(DatabaseConnection $connection): bool
    {
        $driver = $this->driver($connection);

        if ($driver === 'sqlite') {
            return $connection->database === ':memory:' || is_file($connection->database);
        }

        try {
            $statement = $this->connect($connection, $driver)->prepare(self::EXISTS_QUERIES[$driver]);
            $statement->execute([$connection->database]);

            return $statement->fetchColumn() !== false;
        } catch (PDOException $exception) {
            throw DatabaseException::connectionFailed($driver, $exception->getMessage());
        }
    }

    public function createDatabase(DatabaseConnection $connection): void
    {
        $driver = $this->driver($connection);

        if ($driver === 'sqlite') {
            $this->createSqliteFile($connection->database);

            return;
        }

        $pdo = $this->connect($connection, $driver);

        try {
            $pdo->exec($this->createStatement($driver, $connection->database));
        } catch (PDOException $exception) {
            throw DatabaseException::creationFailed($connection->database, $exception->getMessage());
        }
    }

    /**
     * @return bool true when the database was created by this call
     */
    public function createDatabaseIfMissing(DatabaseConnection $connection): bool
    {
        if ($this->databaseExists($connection)) {
            return false;
        }

        $this->createDatabase($connection);

        return true;
    }

    /**
     * @return 'mysql'|'pgsql'|'sqlite'|'sqlsrv'
     */
    private function driver(DatabaseConnection $connection): string
    {
        return match ($connection->driver) {
            'mysql', 'pgsql', 'sqlite', 'sqlsrv' => $connection->driver,
            default => throw DatabaseException::unsupportedDriver($connection->driver),
        };
    }

    /**
     * Connect to the database server itself — no database selected, so the
     * connection works before the target database exists.
     *
     * @param  'mysql'|'pgsql'|'sqlsrv'  $driver  sqlite never reaches here: it is a file, handled by the callers
     */
    private function connect(DatabaseConnection $connection, string $driver): PDO
    {
        $dsn = match ($driver) {
            'mysql' => "mysql:host={$connection->host};port={$connection->port}",
            'pgsql' => "pgsql:host={$connection->host};port={$connection->port}",
            'sqlsrv' => "sqlsrv:Server={$connection->host},{$connection->port}",
        };

        try {
            return new PDO(
                $dsn,
                $connection->username,
                $connection->password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException $exception) {
            throw DatabaseException::connectionFailed($driver, $exception->getMessage());
        }
    }

    /**
     * @param  'mysql'|'pgsql'|'sqlsrv'  $driver
     */
    private function createStatement(string $driver, string $database): string
    {
        $escaped = match ($driver) {
            'mysql' => str_replace('`', '``', $database),
            'pgsql' => str_replace('"', '""', $database),
            'sqlsrv' => str_replace(']', ']]', $database),
        };

        return match ($driver) {
            'mysql' => "CREATE DATABASE IF NOT EXISTS `{$escaped}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
            'pgsql' => "CREATE DATABASE \"{$escaped}\" WITH ENCODING 'UTF8'",
            'sqlsrv' => "CREATE DATABASE [{$escaped}] COLLATE Latin1_General_100_CI_AS_SC_UTF8",
        };
    }

    private function createSqliteFile(string $path): void
    {
        if ($path === ':memory:' || is_file($path)) {
            return;
        }

        $directory = \dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw DatabaseException::creationFailed($path, 'the parent directory could not be created.');
        }

        if (! touch($path)) {
            throw DatabaseException::creationFailed($path, 'the database file could not be created.');
        }
    }
}
