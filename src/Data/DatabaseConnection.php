<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

/**
 * The slice of a config('database.connections.*') entry the package needs
 * to check for and create a database — typed at the config boundary so
 * DatabaseCreator stays container-free.
 */
final readonly class DatabaseConnection
{
    public function __construct(
        public string $driver,
        public string $database,
        public string $host = '127.0.0.1',
        public string $port = '',
        public string $username = '',
        public string $password = '',
    ) {}

    /**
     * @param  array<string, mixed>  $connection  a database.connections.* entry
     */
    public static function fromArray(array $connection): self
    {
        return new self(
            driver: (string) ($connection['driver'] ?? ''),
            database: (string) ($connection['database'] ?? ''),
            host: (string) ($connection['host'] ?? '127.0.0.1'),
            port: (string) ($connection['port'] ?? ''),
            username: (string) ($connection['username'] ?? ''),
            password: (string) ($connection['password'] ?? ''),
        );
    }
}
