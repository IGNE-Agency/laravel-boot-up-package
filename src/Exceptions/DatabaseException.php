<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Exceptions;

final class DatabaseException extends BootUpException
{
    public static function connectionFailed(string $driver, string $reason): self
    {
        return new self(
            "Could not connect to the [{$driver}] database server: {$reason} ".
            'Check the DB_* credentials in your .env file and make sure the database server is running.'
        );
    }

    public static function accessDenied(string $server, string $reason): self
    {
        return new self(
            "The [{$server}] database server rejected the DB_USERNAME / DB_PASSWORD in your .env: {$reason}"
        );
    }

    public static function unknownDatabase(string $server, string $database, string $reason): self
    {
        return new self(
            "The [{$server}] database server has no database named [{$database}]: {$reason} ".
            'Check DB_DATABASE in your .env — a server that provisions its own database bakes the name in '.
            'when its storage is created, and later .env changes never reach it.'
        );
    }

    public static function creationFailed(string $database, string $reason): self
    {
        return new self("Could not create the database [{$database}]: {$reason}");
    }

    public static function unsupportedDriver(string $driver): self
    {
        return new self("Unsupported database driver [{$driver}].");
    }
}
