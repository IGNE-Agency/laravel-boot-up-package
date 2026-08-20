<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Label;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Config\DatabaseConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\DatabaseCredentials;
use Igne\LaravelBootUp\Enums\BootStage;
use Igne\LaravelBootUp\Environment\EnvFile;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prompts for DB_* values missing from .env, writes them back, and refreshes
 * the loaded config so later steps in this same process see fresh values.
 * Also reconciles credentials another server left behind (Sail's `mysql`
 * host after `sail:install`) with the server that drives this run.
 */
#[Stage(BootStage::Database)]
#[Group('database')]
#[Label('Checking database credentials')]
final class EnsureDatabaseCredentials implements Step
{
    /** Hostnames that only resolve inside Sail's Docker network. */
    private const array CONTAINER_HOSTS = ['mysql', 'mariadb', 'pgsql'];

    /** Hosts that only make sense outside Sail's containers. */
    private const array LOOPBACK_HOSTS = ['127.0.0.1', 'localhost'];

    public function __construct(
        private readonly DatabaseConfig $config,
        private readonly EnvFile $envFile,
        private readonly Repository $laravelConfig,
    ) {}

    public function handle(BootContext $context, Closure $next): mixed
    {
        $connection = $this->connection();

        if ($connection === 'sqlite') {
            return $next($context);
        }

        $this->promptForMissing($context, $connection);
        $this->reconcileWithServer($context, $connection);

        return $next($context);
    }

    private function promptForMissing(BootContext $context, string $connection): void
    {
        if (! $this->config->promptMissingCredentials) {
            return;
        }

        $missing = $this->missingKeys();

        if ($missing === []) {
            return;
        }

        $keys = implode(', ', $missing);
        terminal()->warning("Database credentials are missing from .env: {$keys}");

        $this->applyAnswers($this->promptFor($missing, $connection, $context->server?->key()), $connection);
    }

    /**
     * A DB_HOST written for one server does not work under another: Sail's
     * container hostnames never resolve on the host machine, and loopback
     * never reaches Sail's database from inside the containers. Detect the
     * mismatch and offer to fix it for the server that drives this run.
     */
    private function reconcileWithServer(BootContext $context, string $connection): void
    {
        if (! $this->config->reconcileServerCredentials || $context->server === null) {
            return;
        }

        $sail = $context->server->key() === 'sail';
        $host = $this->envFile->valueOr('DB_HOST', '');
        $changes = $this->mismatchedValues($sail, $host, $connection);

        if ($changes->isEmpty()) {
            return;
        }

        $label = $context->server->label();

        terminal()->warning($sail
            ? "DB_HOST is '{$host}' but you're serving with {$label} — Sail's database is only reachable through its container hostname, not through loopback."
            : "DB_HOST is '{$host}' but you're serving with {$label} — that hostname only resolves inside Sail's containers (a leftover from `sail:install`).");

        terminal()->list($this->changeLines($changes));

        if (! terminal()->confirm(
            label: "Update .env to match {$label}?",
            hint: 'DB_PASSWORD is left untouched — check it too if it was changed for the other server.',
        )) {
            terminal()->note('Keeping the current DB_* values — the database steps may fail against this server.');

            return;
        }

        $this->applyAnswers($changes, $connection);
    }

    /**
     * The .env changes that would align DB_* with the chosen server; empty
     * when host and server already agree or the host is a custom one.
     */
    private function mismatchedValues(bool $sail, string $host, string $connection): DatabaseCredentials
    {
        $username = $this->envFile->valueOr('DB_USERNAME', '');

        if (! $sail && in_array($host, self::CONTAINER_HOSTS, true)) {
            return new DatabaseCredentials(
                host: '127.0.0.1',
                username: $username === 'sail' ? 'root' : null,
            );
        }

        if ($sail && in_array($host, self::LOOPBACK_HOSTS, true)) {
            return new DatabaseCredentials(
                host: $this->containerHost($connection),
                username: $username === 'root' ? 'sail' : null,
            );
        }

        return new DatabaseCredentials;
    }

    /**
     * @return list<string>
     */
    private function changeLines(DatabaseCredentials $changes): array
    {
        return collect($changes->toEnvMap())
            ->map(fn (string $value, string $key) => "{$key}: {$this->envFile->valueOr($key, '(empty)')} → {$value}")
            ->values()
            ->all();
    }

    /** Sail's database service hostname for the connection at hand. */
    private function containerHost(string $connection): string
    {
        return match ($connection) {
            'pgsql' => 'pgsql',
            'mariadb' => 'mariadb',
            default => 'mysql',
        };
    }

    /**
     * Write the answers to .env and refresh the loaded connection config so
     * later steps in this same process see fresh values.
     */
    private function applyAnswers(DatabaseCredentials $answers, string $connection): void
    {
        $this->envFile->setMany($answers->toEnvMap());

        foreach ($answers->toConnectionFields() as $field => $value) {
            $this->laravelConfig->set("database.connections.{$connection}.{$field}", $value);
        }

        DB::purge($connection);

        terminal()->success('Database credentials updated in .env.');
    }

    /**
     * The value of DB_CONNECTION — a connection NAME, not a driver, used to
     * refresh database.connections.{name} and purge the right connection.
     * Built-in connection names happen to match their drivers, which is why
     * the container-host and port defaults can switch on it.
     */
    private function connection(): string
    {
        return $this->envFile->valueOr('DB_CONNECTION', (string) $this->laravelConfig->get('database.default'));
    }

    /**
     * DB_PASSWORD legitimately holds an empty string, so only a fully absent
     * key counts as missing; the other keys must also carry a value.
     *
     * @return list<string>
     */
    private function missingKeys(): array
    {
        $missing = $this->envFile->missing(['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME']);

        if (! $this->envFile->has('DB_PASSWORD')) {
            $missing[] = 'DB_PASSWORD';
        }

        return $missing;
    }

    /**
     * @param  list<string>  $missing
     */
    private function promptFor(array $missing, string $connection, ?string $serverKey): DatabaseCredentials
    {
        $defaults = $this->defaultsFor($connection, sail: $serverKey === 'sail');

        $labels = [
            'DB_HOST' => 'Database host',
            'DB_PORT' => 'Database port',
            'DB_DATABASE' => 'Database name',
            'DB_USERNAME' => 'Database username',
        ];

        $answers = [];

        foreach ($missing as $key) {
            $answers[$key] = $key === 'DB_PASSWORD'
                ? terminal()->password(label: 'Database password (leave empty for none)', required: false)
                : terminal()->text(label: $labels[$key], default: $defaults[$key], required: true);
        }

        return DatabaseCredentials::fromEnvMap($answers);
    }

    /**
     * Sensible prompt defaults: Sail's container hostnames and user when
     * the Sail server drives this run, the connection's standard port, and
     * a database name derived from the project folder.
     *
     * @return array<string, string>
     */
    private function defaultsFor(string $connection, bool $sail): array
    {
        return [
            'DB_HOST' => $sail ? $this->containerHost($connection) : '127.0.0.1',
            'DB_PORT' => match ($connection) {
                'pgsql' => '5432',
                'sqlsrv' => '1433',
                default => '3306',
            },
            'DB_DATABASE' => Str::slug(basename(base_path()), '_'),
            'DB_USERNAME' => $sail ? 'sail' : 'root',
        ];
    }
}
