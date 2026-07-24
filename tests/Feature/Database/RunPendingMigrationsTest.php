<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\DatabaseConfig;
use Igne\LaravelBootUp\Contracts\ProvidesDatabase;
use Igne\LaravelBootUp\Contracts\RewritesCommands;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\CommandRewrites;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Database\PendingMigrations;
use Igne\LaravelBootUp\Database\Steps\RunPendingMigrations;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\Terminal\NullTerminal;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Igne\LaravelBootUp\Services\Poller;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-run-migrations-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    Prompt::fake();

    // Resolve app(Factory::class) lazily so Process::fake() is honoured.
    $this->step = fn (?DatabaseConfig $config = null): RunPendingMigrations => new RunPendingMigrations(
        $config ?? new DatabaseConfig,
        new PendingMigrations(app('migrator')),
        new ProcessRunner(
            processes: app(Factory::class),
            ledger: new ProcessLedger($this->dir.'/processes.json'),
            terminal: new NullTerminal,
            poller: new Poller,
            logDirectory: $this->dir.'/logs',
            runtimeDirectory: $this->dir.'/runtime',
        ),
        new CommandRewriter,
    );

    $this->addPendingMigration = function (): void {
        file_put_contents(
            $this->dir.'/2024_01_01_000000_create_dummies_table.php',
            "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration {};\n",
        );

        app('migrator')->path($this->dir);
    };

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

        public function start(ServeContext $context): void {}

        public function stop(): void {}

        public function url(): string
        {
            return 'http://localhost';
        }
    };
});

afterEach(function (): void {
    if (is_dir($this->dir)) {
        exec('rm -rf '.escapeshellarg($this->dir));
    }
});

function runMigrationsCommandOf(object $process): string
{
    return is_array($process->command) ? implode(' ', $process->command) : $process->command;
}

test('skips with a note when --no-migrate was passed', function (): void {
    Process::fake();
    ($this->addPendingMigration)();

    $context = new ServeContext(new ServeOptions(migrate: false));

    $result = ($this->step)()->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('--no-migrate');
});

test('skips with a note when automatic migrations are disabled in configuration', function (): void {
    Process::fake();
    ($this->addPendingMigration)();

    $config = new DatabaseConfig(migrationsAuto: false);

    ($this->step)($config)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('disabled');
});

test('an up-to-date database migrates nothing host-side', function (): void {
    Process::fake();

    ($this->step)()->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('up to date');
});

test('pending migrations run a host-side, un-rewritten migrate --force', function (): void {
    Process::fake(['*' => Process::result()]);
    ($this->addPendingMigration)();

    ($this->step)()->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertRan(fn ($process): bool => runMigrationsCommandOf($process) === 'php artisan migrate --force');
    Prompt::assertStrippedOutputContains('1 pending migration');
});

test('the seed option runs db:seed after migrating', function (): void {
    Process::fake(['*' => Process::result()]);
    ($this->addPendingMigration)();

    ($this->step)()->handle(new ServeContext(new ServeOptions(seed: true)), fn ($passed) => $passed);

    Process::assertRan(fn ($process): bool => runMigrationsCommandOf($process) === 'php artisan migrate --force');
    Process::assertRan(fn ($process): bool => runMigrationsCommandOf($process) === 'php artisan db:seed');
});

test('an explicit seed runs even when the database is already up to date', function (): void {
    Process::fake(['*' => Process::result()]);

    ($this->step)()->handle(new ServeContext(new ServeOptions(seed: true)), fn ($passed) => $passed);

    Process::assertDidntRun(fn ($process): bool => str_contains(runMigrationsCommandOf($process), 'migrate --force'));
    Process::assertRan(fn ($process): bool => runMigrationsCommandOf($process) === 'php artisan db:seed');
});

test('--no-migrate combined with --seed seeds without migrating', function (): void {
    Process::fake(['*' => Process::result()]);
    ($this->addPendingMigration)();

    ($this->step)()->handle(new ServeContext(new ServeOptions(seed: true, migrate: false)), fn ($passed) => $passed);

    Process::assertDidntRun(fn ($process): bool => str_contains(runMigrationsCommandOf($process), 'migrate'));
    Process::assertRan(fn ($process): bool => runMigrationsCommandOf($process) === 'php artisan db:seed');
});

test('a confirmed --fresh drops and re-runs everything in one command', function (): void {
    Prompt::fake(['y', Laravel\Prompts\Key::ENTER]);
    Process::fake(['*' => Process::result()]);

    ($this->step)()->handle(new ServeContext(new ServeOptions(fresh: true)), fn ($passed) => $passed);

    Process::assertRan(fn ($process): bool => runMigrationsCommandOf($process) === 'php artisan migrate:fresh --force');
    Prompt::assertStrippedOutputContains('drops ALL tables');
});

test('--fresh with --seed folds the seed into migrate:fresh', function (): void {
    Prompt::fake(['y', Laravel\Prompts\Key::ENTER]);
    Process::fake(['*' => Process::result()]);

    ($this->step)()->handle(new ServeContext(new ServeOptions(seed: true, fresh: true)), fn ($passed) => $passed);

    Process::assertRan(fn ($process): bool => runMigrationsCommandOf($process) === 'php artisan migrate:fresh --force --seed');
    Process::assertDidntRun(fn ($process): bool => runMigrationsCommandOf($process) === 'php artisan db:seed');
});

test('a declined --fresh falls back to the normal pending-migrations flow', function (): void {
    Prompt::fake([Laravel\Prompts\Key::ENTER]);
    Process::fake(['*' => Process::result()]);
    ($this->addPendingMigration)();

    ($this->step)()->handle(new ServeContext(new ServeOptions(fresh: true)), fn ($passed) => $passed);

    Process::assertDidntRun(fn ($process): bool => str_contains(runMigrationsCommandOf($process), 'migrate:fresh'));
    Process::assertRan(fn ($process): bool => runMigrationsCommandOf($process) === 'php artisan migrate --force');
    Prompt::assertStrippedOutputContains('Fresh migration declined');
});

test('--no-migrate beats --fresh with a warning', function (): void {
    Process::fake();

    ($this->step)()->handle(new ServeContext(new ServeOptions(migrate: false, fresh: true)), fn ($passed) => $passed);

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('--fresh ignored');
});

test('--fresh runs through the server rewrites when the host cannot reach the database', function (): void {
    Prompt::fake(['y', Laravel\Prompts\Key::ENTER]);
    Process::fake(['*' => Process::result()]);

    ($this->step)()->handle(new ServeContext(new ServeOptions(fresh: true), $this->sailServer), fn ($passed) => $passed);

    Process::assertRan(fn ($process): bool => runMigrationsCommandOf($process) === './vendor/bin/sail artisan migrate:fresh --force');
});

test('sail checks pending migrations in the container and migrates through sail', function (): void {
    Process::fake([
        '*migrate:status*' => Process::result(output: "2024_01_01_000000_create_dummies_table  Pending\n"),
        '*' => Process::result(),
    ]);

    $context = new ServeContext(new ServeOptions, $this->sailServer);

    ($this->step)()->handle($context, fn ($passed) => $passed);

    Process::assertRan(fn ($process): bool => runMigrationsCommandOf($process) === './vendor/bin/sail artisan migrate:status --pending');
    Process::assertRan(fn ($process): bool => runMigrationsCommandOf($process) === './vendor/bin/sail artisan migrate --force');
});

test('sail with no pending migrations skips the migrate', function (): void {
    Process::fake([
        '*migrate:status*' => Process::result(output: "INFO  No pending migrations.\n"),
        '*' => Process::result(),
    ]);

    $context = new ServeContext(new ServeOptions, $this->sailServer);

    ($this->step)()->handle($context, fn ($passed) => $passed);

    Process::assertDidntRun(fn ($process): bool => str_contains(runMigrationsCommandOf($process), 'migrate --force'));
    Prompt::assertStrippedOutputContains('up to date');
});

test('sail seeds through the container after migrating', function (): void {
    Process::fake([
        '*migrate:status*' => Process::result(output: "2024_01_01_000000_create_dummies_table  Pending\n"),
        '*' => Process::result(),
    ]);

    $context = new ServeContext(new ServeOptions(seed: true), $this->sailServer);

    ($this->step)()->handle($context, fn ($passed) => $passed);

    Process::assertRan(fn ($process): bool => runMigrationsCommandOf($process) === './vendor/bin/sail artisan db:seed');
});
