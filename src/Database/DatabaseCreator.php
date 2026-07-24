<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database;

use Igne\LaravelBootUp\Exceptions\DatabaseException;
use PDO;
use PDOException;

/**
 * Driver-agnostic database existence checks and creation. Server drivers
 * (mysql/pgsql/sqlsrv) connect via PDO without selecting a database — the
 * database may not exist yet; sqlite is a plain file on disk.
 *
 * Connection settings arrive as an explicit array (the shape of a
 * config('database.connections.*') entry) so the class stays container-free.
 */
final class DatabaseCreator
{
    private const EXISTS_QUERIES = [
        'mysql' => 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
        'pgsql' => 'SELECT datname FROM pg_database WHERE datname = ?',
        'sqlsrv' => 'SELECT name FROM sys.databases WHERE name = ?',
    ];

    /**
     * @param  array<string, mixed>  $connection
     */
    public function databaseExists(array $connection): bool
    {
        $driver = $this->driver($connection);

        if ($driver === 'sqlite') {
            $path = (string) ($connection['database'] ?? '');

            return $path === ':memory:' || is_file($path);
        }

        try {
            $statement = $this->connect($connection, $driver)->prepare(self::EXISTS_QUERIES[$driver]);
            $statement->execute([(string) ($connection['database'] ?? '')]);

            return $statement->fetchColumn() !== false;
        } catch (PDOException $exception) {
            throw DatabaseException::connectionFailed($driver, $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    public function createDatabase(array $connection): void
    {
        $driver = $this->driver($connection);
        $database = (string) ($connection['database'] ?? '');

        if ($driver === 'sqlite') {
            $this->createSqliteFile($database);

            return;
        }

        $pdo = $this->connect($connection, $driver);

        try {
            $pdo->exec($this->createStatement($driver, $database));
        } catch (PDOException $exception) {
            throw DatabaseException::creationFailed($database, $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return bool true when the database was created by this call
     */
    public function createDatabaseIfMissing(array $connection): bool
    {
        if ($this->databaseExists($connection)) {
            return false;
        }

        $this->createDatabase($connection);

        return true;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function driver(array $connection): string
    {
        $driver = (string) ($connection['driver'] ?? '');

        return match ($driver) {
            'mysql', 'pgsql', 'sqlite', 'sqlsrv' => $driver,
            default => throw DatabaseException::unsupportedDriver($driver),
        };
    }

    /**
     * Connect to the database server itself — no database selected, so the
     * connection works before the target database exists.
     *
     * @param  array<string, mixed>  $connection
     */
    private function connect(array $connection, string $driver): PDO
    {
        $host = (string) ($connection['host'] ?? '127.0.0.1');
        $port = (string) ($connection['port'] ?? '');

        $dsn = match ($driver) {
            'mysql' => "mysql:host={$host};port={$port}",
            'pgsql' => "pgsql:host={$host};port={$port}",
            'sqlsrv' => "sqlsrv:Server={$host},{$port}",
        };

        try {
            return new PDO(
                $dsn,
                (string) ($connection['username'] ?? ''),
                (string) ($connection['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException $exception) {
            throw DatabaseException::connectionFailed($driver, $exception->getMessage());
        }
    }

    private function createStatement(string $driver, string $database): string
    {
        return match ($driver) {
            'mysql' => sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                str_replace('`', '``', $database),
            ),
            'pgsql' => sprintf(
                'CREATE DATABASE "%s" WITH ENCODING \'UTF8\'',
                str_replace('"', '""', $database),
            ),
            'sqlsrv' => sprintf(
                'CREATE DATABASE [%s] COLLATE Latin1_General_100_CI_AS_SC_UTF8',
                str_replace(']', ']]', $database),
            ),
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
