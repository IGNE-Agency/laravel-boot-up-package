<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Sail;

use Igne\LaravelBootUp\Config\SailConfig;
use Igne\LaravelBootUp\Config\SetupConfig;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\DatabaseCredentials;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Environment\EnvRestorePoint;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one-time credentials decision for a fresh database volume.
 *
 * Runs before the first `sail up` creates the volume, because that is when
 * the database image reads DB_DATABASE / DB_USERNAME / DB_PASSWORD from .env
 * and bakes them in for the volume's lifetime. The answers are written to
 * .env permanently — restoring them at teardown is exactly what desynced
 * .env from the volume before — and any stale restore-point records for the
 * DB_* keys are dropped for the same reason.
 */
final class SailDatabaseCredentials
{
    private const array DB_KEYS = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

    private bool $handled = false;

    public function __construct(
        private readonly SailDatabaseVolume $volume,
        private readonly EnvFile $envFile,
        private readonly EnvRestorePoint $envRestore,
        private readonly SailConfig $sailConfig,
        private readonly SetupConfig $setupConfig,
        private readonly Repository $laravelConfig,
    ) {}

    public function ensureBeforeFirstUp(BootContext $context): void
    {
        if ($this->handled || ! $this->sailConfig->promptDatabaseCredentials) {
            return;
        }

        $connection = $this->connection();

        if ($connection === 'sqlite' || ! $this->volume->isFresh()) {
            return;
        }

        // Guarded by a flag rather than by isFresh(): the volume still does
        // not exist between prepare() and start()->prepare(), and asking the
        // same questions twice in one boot would be absurd.
        $this->handled = true;

        $this->apply($this->credentials($context, $connection), $connection);
    }

    private function credentials(BootContext $context, string $connection): DatabaseCredentials
    {
        $defaults = $this->defaults($connection);

        terminal()->summary(
            "Sail is about to create the [{$connection}] database volume",
            [
                'The container bakes DB_DATABASE, DB_USERNAME and DB_PASSWORD into the volume when it is first created.',
                'Changing .env afterwards does not update them — these credentials are permanent for the volume\'s lifetime.',
            ],
        );

        if ($this->setupConfig->autoAccept || $context->options->autoAccept) {
            terminal()->warning(
                "Creating it with database '{$defaults->database}' and username '{$defaults->username}' — "
                .'your .env values, with Sail defaults filling any blanks.',
            );

            return $defaults;
        }

        $database = terminal()->text(label: 'Database name', default: (string) $defaults->database, required: true);

        $username = terminal()->text(
            label: 'Database username',
            default: (string) $defaults->username,
            required: true,
            validate: fn (string $value): ?string => $this->refusesUsername($value, $connection),
        );

        $password = terminal()->password(
            label: 'Database password',
            hint: "Enter keeps '{$defaults->password}' — the value the .env will carry.",
        );

        return new DatabaseCredentials(
            database: $database,
            username: $username,
            password: $password === '' ? $defaults->password : $password,
        );
    }

    /**
     * The current .env values, with Sail's conventions filling any blanks.
     * A `root` username is normalized away for MySQL-family connections:
     * the image refuses MYSQL_USER=root (it creates root itself), and a
     * container crash-looping on its first boot is exactly the failure this
     * prompt exists to prevent.
     */
    private function defaults(string $connection): DatabaseCredentials
    {
        $username = $this->envFile->valueOr('DB_USERNAME', '');
        $password = $this->envFile->valueOr('DB_PASSWORD', '');

        if ($username === '' || $this->refusesUsername($username, $connection) !== null) {
            $username = 'sail';
        }

        return new DatabaseCredentials(
            database: $this->envFile->valueOr('DB_DATABASE', Str::slug(basename(base_path()), '_')),
            username: $username,
            password: $password === '' ? 'password' : $password,
        );
    }

    private function refusesUsername(string $username, string $connection): ?string
    {
        return \in_array($connection, ['mysql', 'mariadb'], true) && strtolower(trim($username)) === 'root'
            ? "The MySQL image refuses MYSQL_USER=root — it creates root itself (with DB_PASSWORD). Pick a regular user such as 'sail'."
            : null;
    }

    /**
     * Written permanently, then the DB_* keys are dropped from the restore
     * point: the volume bakes these values in, so .env must keep matching
     * them after this session ends — including records left behind by boots
     * that still treated them as session-scoped.
     */
    private function apply(DatabaseCredentials $answers, string $connection): void
    {
        $this->envFile->setMany($answers->toEnvMap());
        $this->envRestore->forgetKeys(self::DB_KEYS);

        foreach ($answers->toConnectionFields() as $field => $value) {
            $this->laravelConfig->set("database.connections.{$connection}.{$field}", $value);
        }

        DB::purge($connection);

        terminal()->success('Database credentials written to .env — Sail will create the volume with them.');
    }

    private function connection(): string
    {
        return $this->envFile->valueOr('DB_CONNECTION', (string) $this->laravelConfig->get('database.default'));
    }
}
