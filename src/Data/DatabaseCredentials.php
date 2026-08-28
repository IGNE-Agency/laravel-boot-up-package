<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

use Illuminate\Support\Arr;

/**
 * A partial set of DB_* values headed for the .env file — null means
 * "leave that key untouched". Field names mirror the connection config
 * fields the same values refresh in the loaded repository.
 */
final readonly class DatabaseCredentials
{
    public function __construct(
        public ?string $host = null,
        public ?string $port = null,
        public ?string $database = null,
        public ?string $username = null,
        public ?string $password = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->toEnvMap() === [];
    }

    /**
     * Only the carried values, keyed by their .env name.
     *
     * @return array<string, string>
     */
    public function toEnvMap(): array
    {
        return Arr::whereNotNull([
            'DB_HOST' => $this->host,
            'DB_PORT' => $this->port,
            'DB_DATABASE' => $this->database,
            'DB_USERNAME' => $this->username,
            'DB_PASSWORD' => $this->password,
        ]);
    }

    /**
     * The same values keyed by their database.connections.* config field.
     *
     * @return array<string, string>
     */
    public function toConnectionFields(): array
    {
        return Arr::whereNotNull([
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
        ]);
    }

    /**
     * @param  array<string, string>  $values  keyed by .env name (DB_HOST, ...)
     */
    public static function fromEnvMap(array $values): self
    {
        return new self(
            host: $values['DB_HOST'] ?? null,
            port: $values['DB_PORT'] ?? null,
            database: $values['DB_DATABASE'] ?? null,
            username: $values['DB_USERNAME'] ?? null,
            password: $values['DB_PASSWORD'] ?? null,
        );
    }
}
