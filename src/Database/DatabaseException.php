<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database;

use Igne\LaravelBootUp\Support\BootUpException;

final class DatabaseException extends BootUpException
{
    public static function connectionFailed(string $driver, string $reason): self
    {
        return new self(
            "Could not connect to the [{$driver}] database server: {$reason} ".
            'Check the DB_* credentials in your .env file and make sure the database server is running.'
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
