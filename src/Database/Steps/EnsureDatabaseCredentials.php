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

    public function __construct(
        private readonly DatabaseConfig $config,
        private readonly EnvFile $envFile,
        private readonly Repository $laravelConfig,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if (! $this->config->promptMissingCredentials) {
            return $next($context);
        }

        $driver = $this->driver();

        if ($driver === 'sqlite') {
            return $next($context);
        }

        $missing = $this->missingKeys();

        if ($missing === []) {
            return $next($context);
        }

        $keys = implode(', ', $missing);
        terminal()->warning("Database credentials are missing from .env: {$keys}");

        $this->applyAnswers($this->promptFor($missing, $driver, $context->server?->key()), $driver);

        return $next($context);
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

        terminal()->success('Database credentials saved to .env.');
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
            'DB_HOST' => $sail ? 'mysql' : '127.0.0.1',
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
