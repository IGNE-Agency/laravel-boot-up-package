<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\DatabaseConfig;
use Igne\LaravelBootUp\Contracts\ProvidesDatabase;
use Igne\LaravelBootUp\Contracts\ResetsDatabaseStorage;
use Igne\LaravelBootUp\Contracts\RewritesCommands;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Data\CommandRewrites;
use Igne\LaravelBootUp\Database\DatabaseFailureDetector;
use Igne\LaravelBootUp\Database\DatabaseProbe;
use Igne\LaravelBootUp\Database\Steps\VerifyDatabaseConnection;
use Igne\LaravelBootUp\Exceptions\DatabaseException;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Igne\LaravelBootUp\Services\Poller;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-db-verify-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    Prompt::fake();

    // Resolve app(Factory::class) lazily so Process::fake() is honoured. The
    // zero default timeout means a single attempt — the pre-poll behavior —
    // so only the retry tests pay for waiting.
    $this->step = fn (int $readyTimeout = 0): VerifyDatabaseConnection => new VerifyDatabaseConnection(
        new ProcessRunner(
            processes: app(Factory::class),
            ledger: new ProcessLedger($this->dir.'/processes.json'),
            logDirectory: $this->dir.'/logs',
        ),
        new CommandRewriter,
        new DatabaseProbe(app('db'), config()),
        new Poller,
        new DatabaseConfig(readyTimeoutSeconds: $readyTimeout),
        new DatabaseFailureDetector,
        pollIntervalMs: 1,
    );

    $this->sailServer = new class implements ProvidesDatabase, RewritesCommands, Server
    {
        public function databaseReachableFromHost(): bool
        {
            return false;
        }

        public function key(): string
        {
            return 'sail';
        }

        public function label(): string
        {
            return 'Laravel Sail';
        }

        public function commandRewrites(): CommandRewrites
        {
            return new CommandRewrites(
                replaces: ['php artisan' => 'artisan'],
                prefixes: ['php', 'composer', 'artisan'],
                prefix: './vendor/bin/sail',
            );
        }

        public function isRunning(): bool
        {
            return true;
        }

        public function start(BootContext $context): void {}

        public function stop(): void {}

        public function url(): string
        {
            return 'http://localhost';
        }
    };
});

/**
 * A sail-shaped server whose database storage can be destroyed and
 * recreated — for the recovery-offer tests.
 */
function resettingSailServer(bool $existedBeforeBoot = true): ProvidesDatabase&ResetsDatabaseStorage&RewritesCommands&Server
{
    return new class($existedBeforeBoot) implements ProvidesDatabase, ResetsDatabaseStorage, RewritesCommands, Server
    {
        public int $resets = 0;

        public function __construct(private readonly bool $existedBeforeBoot) {}

        public function databaseStorageExistedBeforeBoot(): bool
        {
            return $this->existedBeforeBoot;
        }

        public function databaseStorageResettable(): bool
        {
            return true;
        }

        public function databaseStorageLabel(): string
        {
            return 'Docker volume [app_sail-mysql]';
        }

        public function resetDatabaseStorage(): void
        {
            $this->resets++;
        }

        public function databaseStorageResetInstructions(): array
        {
            return ['./vendor/bin/sail down', 'docker volume rm app_sail-mysql', './vendor/bin/sail up -d'];
        }

        public function databaseReachableFromHost(): bool
        {
            return false;
        }

        public function key(): string
        {
            return 'sail';
        }

        public function label(): string
        {
            return 'Laravel Sail';
        }

        public function commandRewrites(): CommandRewrites
        {
            return new CommandRewrites(
                replaces: ['php artisan' => 'artisan'],
                prefixes: ['php', 'composer', 'artisan'],
                prefix: './vendor/bin/sail',
            );
        }

        public function isRunning(): bool
        {
            return true;
        }

        public function start(BootContext $context): void {}

        public function stop(): void {}

        public function url(): string
        {
            return 'http://localhost';
        }
    };
}

const SAIL_DB_PING = './vendor/bin/sail artisan app:db-ping';

afterEach(function (): void {
    if (is_dir($this->dir)) {
        exec('rm -rf '.escapeshellarg($this->dir));
    }
});

test('a working sqlite connection verifies host-side without spawning processes', function (): void {
    Process::fake();

    $context = new BootContext(new BootOptions);

    $result = ($this->step)()->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('verified');
});

test('an unreachable host connection throws with the driver in the message', function (): void {
    config()->set('database.connections.mysql', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '1',
        'database' => 'igne',
        'username' => 'root',
        'password' => '',
    ]);
    config()->set('database.default', 'mysql');

    ($this->step)()->handle(new BootContext(new BootOptions), fn ($passed) => $passed);
})->throws(DatabaseException::class, 'mysql');

test('sail verifies through the container with a rewritten db-ping', function (): void {
    Process::fake(['*' => Process::result(output: 'Database connection established [mysql].')]);

    $context = new BootContext(new BootOptions, $this->sailServer);

    $result = ($this->step)()->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertRan(fn ($process): bool => implode(' ', $process->command) === SAIL_DB_PING);
    Prompt::assertStrippedOutputContains('verified');
});

test('a connected database with no migrations table verifies', function (): void {
    // The state every freshly created volume is in. The check asks the
    // database for a connection and nothing about schema, so the absence
    // of a migrations table is none of its business — probing with
    // migrate:status is what used to fail every first boot here.
    Process::fake(['*' => Process::result(output: 'Database connection established [mysql].')]);

    $context = new BootContext(new BootOptions, $this->sailServer);

    $result = ($this->step)()->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertNotRan(fn ($process): bool => str_contains(implode(' ', $process->command), 'migrate'));
    Prompt::assertStrippedOutputContains('verified');
});

test('a failing sail check throws with the trimmed error output', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: "  the mysql container is not running \n")]);

    $context = new BootContext(new BootOptions, $this->sailServer);

    ($this->step)()->handle($context, fn ($passed) => $passed);
})->throws(DatabaseException::class, 'the mysql container is not running');

test('sail retries until the database becomes ready', function (): void {
    // A container initializing its database on first boot refuses
    // connections for a while — that is a wait, not a failure.
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(exitCode: 1, errorOutput: 'SQLSTATE[HY000] [2002] Connection refused'))
        ->push(Process::result(exitCode: 1, errorOutput: 'SQLSTATE[HY000] [2002] Connection refused'))
        ->push(Process::result(output: 'Database connection established [mysql].')),
    ]);

    $context = new BootContext(new BootOptions, $this->sailServer);

    $result = ($this->step)(readyTimeout: 5)->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertRanTimes(fn ($process): bool => implode(' ', $process->command) === SAIL_DB_PING, 3);
    Prompt::assertStrippedOutputContains('verified');
});

test('a database that never becomes ready throws with timeout wording', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);

    $context = new BootContext(new BootOptions, $this->sailServer);

    ($this->step)()->handle($context, fn ($passed) => $passed);
})->throws(DatabaseException::class, 'did not accept connections within 0 seconds');

test('rejected credentials fail fast instead of waiting out the timeout', function (): void {
    Process::fake(['*' => Process::result(
        exitCode: 1,
        errorOutput: "SQLSTATE[HY000] [1045] Access denied for user 'sail'@'192.168.65.1' (using password: YES)",
    )]);

    // A plain sail server has no storage to offer resetting, so the
    // rejection surfaces directly — after exactly one attempt, despite the
    // generous timeout.
    $context = new BootContext(new BootOptions, $this->sailServer);

    expect(fn () => ($this->step)(readyTimeout: 60)->handle($context, fn ($passed) => $passed))
        ->toThrow(DatabaseException::class, 'rejected the DB_USERNAME / DB_PASSWORD');

    Process::assertRanTimes(fn ($process): bool => implode(' ', $process->command) === SAIL_DB_PING, 1);
});

test('a stale volume earns a recovery offer, and accepting it recreates and re-verifies', function (): void {
    Prompt::fake(['y', Key::ENTER]);
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(exitCode: 1, errorOutput: "SQLSTATE[HY000] [1045] Access denied for user 'sail'"))
        ->push(Process::result(output: 'Database connection established [mysql].')),
    ]);

    $server = resettingSailServer();
    $context = new BootContext(new BootOptions, $server);

    $result = ($this->step)()->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context)
        ->and($server->resets)->toBe(1);
    Prompt::assertStrippedOutputContains('Docker volume [app_sail-mysql]');
    Prompt::assertStrippedOutputContains('verified');
});

test('declining the recovery offer prints the manual steps and throws', function (): void {
    // Plain ENTER takes the default, which is No — destroying data must
    // never be the path of least resistance.
    Prompt::fake([Key::ENTER]);
    Process::fake(['*' => Process::result(
        exitCode: 1,
        errorOutput: "SQLSTATE[HY000] [1045] Access denied for user 'sail'",
    )]);

    $server = resettingSailServer();
    $context = new BootContext(new BootOptions, $server);

    expect(fn () => ($this->step)()->handle($context, fn ($passed) => $passed))
        ->toThrow(DatabaseException::class, 'rejected the DB_USERNAME / DB_PASSWORD');

    expect($server->resets)->toBe(0);
    Prompt::assertStrippedOutputContains('docker volume rm app_sail-mysql');
});

test('--yes never accepts the recovery offer on its own', function (): void {
    // Recreating the volume destroys data; an unattended run gets the
    // default answer instead, which is No.
    Prompt::fake([Key::ENTER]);
    Process::fake(['*' => Process::result(
        exitCode: 1,
        errorOutput: "SQLSTATE[HY000] [1045] Access denied for user 'sail'",
    )]);

    $server = resettingSailServer();
    $context = new BootContext(new BootOptions(autoAccept: true), $server);

    expect(fn () => ($this->step)()->handle($context, fn ($passed) => $passed))
        ->toThrow(DatabaseException::class);

    expect($server->resets)->toBe(0);
});

test('storage created by this very boot gets no recovery offer', function (): void {
    // The volume was just initialized from the current .env, so rejected
    // credentials cannot mean stale storage — recreating it would change
    // nothing. Prompt::fake() with no queued keys makes any prompt fail.
    Process::fake(['*' => Process::result(
        exitCode: 1,
        errorOutput: "SQLSTATE[HY000] [1045] Access denied for user 'sail'",
    )]);

    $server = resettingSailServer(existedBeforeBoot: false);
    $context = new BootContext(new BootOptions, $server);

    expect(fn () => ($this->step)()->handle($context, fn ($passed) => $passed))
        ->toThrow(DatabaseException::class, 'rejected the DB_USERNAME / DB_PASSWORD');

    expect($server->resets)->toBe(0);
});

test('an unknown database fails fast and names the database', function (): void {
    // DB_DATABASE naming something the volume never created: waiting cannot
    // conjure a database, so the poll settles on the first attempt despite
    // the generous timeout.
    config()->set('database.connections.mysql', [
        'driver' => 'mysql',
        'host' => 'mysql',
        'port' => '3306',
        'database' => 'igne-dashboard',
        'username' => 'sail',
        'password' => 'password',
    ]);
    config()->set('database.default', 'mysql');

    Process::fake(['*' => Process::result(
        exitCode: 1,
        errorOutput: "SQLSTATE[HY000] [1049] Unknown database 'igne-dashboard'",
    )]);

    $context = new BootContext(new BootOptions, $this->sailServer);

    expect(fn () => ($this->step)(readyTimeout: 60)->handle($context, fn ($passed) => $passed))
        ->toThrow(DatabaseException::class, 'has no database named [igne-dashboard]');

    Process::assertRanTimes(fn ($process): bool => implode(' ', $process->command) === SAIL_DB_PING, 1);
});

test('a stale volume missing the database earns the same recovery offer', function (): void {
    // The database name is baked into the volume exactly like the
    // credentials are, so recreating it is the one thing that can fix this.
    Prompt::fake(['y', Key::ENTER]);
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(exitCode: 1, errorOutput: "SQLSTATE[HY000] [1049] Unknown database 'igne-dashboard'"))
        ->push(Process::result(output: 'Database connection established [mysql].')),
    ]);

    $server = resettingSailServer();
    $context = new BootContext(new BootOptions, $server);

    $result = ($this->step)()->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context)
        ->and($server->resets)->toBe(1);
    Prompt::assertStrippedOutputContains('verified');
});
