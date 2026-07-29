<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database\Steps;

use Closure;
use Igne\LaravelBootUp\Config\DatabaseConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
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
final class EnsureDatabaseCredentials implements Step
{
    private const CONFIG_FIELDS = [
        'DB_HOST' => 'host',
        'DB_PORT' => 'port',
        'DB_DATABASE' => 'database',
        'DB_USERNAME' => 'username',
        'DB_PASSWORD' => 'password',
    ];

    /** Hostnames that only resolve inside Sail's Docker network. */
    private const CONTAINER_HOSTS = ['mysql', 'mariadb', 'pgsql'];

    /** Hosts that only make sense outside Sail's containers. */
    private const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost'];

    public function __construct(
        private readonly DatabaseConfig $config,
        private readonly EnvFile $envFile,
        private readonly Repository $laravelConfig,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        $driver = $this->driver();

        if ($driver === 'sqlite') {
            return $next($context);
        }

        $this->promptForMissing($context, $driver);
        $this->reconcileWithServer($context, $driver);

        return $next($context);
    }

    private function promptForMissing(ServeContext $context, string $driver): void
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

        $this->applyAnswers($this->promptFor($missing, $driver, $context->server?->key()), $driver);
    }

    /**
     * A DB_HOST written for one server does not work under another: Sail's
     * container hostnames never resolve on the host machine, and loopback
     * never reaches Sail's database from inside the containers. Detect the
     * mismatch and offer to fix it for the server that drives this run.
     */
    private function reconcileWithServer(ServeContext $context, string $driver): void
    {
        if (! $this->config->reconcileServerCredentials || $context->server === null) {
            return;
        }

        $sail = $context->server->key() === 'sail';
        $host = $this->envFile->valueOr('DB_HOST', '');
        $changes = $this->mismatchedValues($sail, $host, $driver);

        if ($changes === []) {
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

        $this->applyAnswers($changes, $driver);
    }

    /**
     * The .env changes that would align DB_* with the chosen server; empty
     * when host and server already agree or the host is a custom one.
     *
     * @return array<string, string>
     */
    private function mismatchedValues(bool $sail, string $host, string $driver): array
    {
        $username = $this->envFile->valueOr('DB_USERNAME', '');

        if (! $sail && in_array($host, self::CONTAINER_HOSTS, true)) {
            return ['DB_HOST' => '127.0.0.1']
                + ($username === 'sail' ? ['DB_USERNAME' => 'root'] : []);
        }

        if ($sail && in_array($host, self::LOOPBACK_HOSTS, true)) {
            return ['DB_HOST' => $this->containerHost($driver)]
                + ($username === 'root' ? ['DB_USERNAME' => 'sail'] : []);
        }

        return [];
    }

    /**
     * @param  array<string, string>  $changes
     * @return list<string>
     */
    private function changeLines(array $changes): array
    {
        return collect($changes)
            ->map(fn (string $value, string $key) => "{$key}: {$this->envFile->valueOr($key, '(empty)')} → {$value}")
            ->values()
            ->all();
    }

    /** Sail's database service hostname for the driver at hand. */
    private function containerHost(string $driver): string
    {
        return match ($driver) {
            'pgsql' => 'pgsql',
            'mariadb' => 'mariadb',
            default => 'mysql',
        };
    }

    /**
     * Write the answers to .env and refresh the loaded connection config so
     * later steps in this same process see fresh values.
     *
     * @param  array<string, string>  $answers
     */
    private function applyAnswers(array $answers, string $driver): void
    {
        $this->envFile->setMany($answers);

        foreach ($answers as $key => $value) {
            $field = self::CONFIG_FIELDS[$key];
            $this->laravelConfig->set("database.connections.{$driver}.{$field}", $value);
        }

        DB::purge($driver);

        terminal()->success('Database credentials updated in .env.');
    }

    private function driver(): string
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
     * @return array<string, string>
     */
    private function promptFor(array $missing, string $driver, ?string $serverKey): array
    {
        $defaults = $this->defaultsFor($driver, sail: $serverKey === 'sail');

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

        return $answers;
    }

    /**
     * Sensible prompt defaults: Sail's container hostnames and user when
     * the Sail server drives this run, the driver's standard port, and a
     * database name derived from the project folder.
     *
     * @return array<string, string>
     */
    private function defaultsFor(string $driver, bool $sail): array
    {
        return [
            'DB_HOST' => $sail ? $this->containerHost($driver) : '127.0.0.1',
            'DB_PORT' => match ($driver) {
                'pgsql' => '5432',
                'sqlsrv' => '1433',
                default => '3306',
            },
            'DB_DATABASE' => Str::slug(basename(base_path()), '_'),
            'DB_USERNAME' => $sail ? 'sail' : 'root',
        ];
    }
}
