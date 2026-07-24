<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Contracts\ProvidesDatabase;
use Igne\LaravelBootUp\Contracts\RewritesCommands;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\CommandRewrites;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Database\Steps\VerifyDatabaseConnection;
use Igne\LaravelBootUp\Exceptions\DatabaseException;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\Terminal\NullTerminal;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Igne\LaravelBootUp\Services\Poller;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-db-verify-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    Prompt::fake();

    // Resolve app(Factory::class) lazily so Process::fake() is honoured.
    $this->step = fn (): VerifyDatabaseConnection => new VerifyDatabaseConnection(
        new ProcessRunner(
            processes: app(Factory::class),
            ledger: new ProcessLedger($this->dir.'/processes.json'),
            terminal: new NullTerminal,
            poller: new Poller,
            logDirectory: $this->dir.'/logs',
            runtimeDirectory: $this->dir.'/runtime',
        ),
        new CommandRewriter,
        config(),
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

test('a working sqlite connection verifies host-side without spawning processes', function (): void {
    Process::fake();

    $context = new ServeContext(new ServeOptions);

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

    ($this->step)()->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);
})->throws(DatabaseException::class, 'mysql');

test('sail verifies through the container with a rewritten migrate:status', function (): void {
    Process::fake(['*' => Process::result(output: 'Migration name  Batch / Status')]);

    $context = new ServeContext(new ServeOptions, $this->sailServer);

    $result = ($this->step)()->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertRan(fn ($process): bool => implode(' ', $process->command) === './vendor/bin/sail artisan migrate:status');
    Prompt::assertStrippedOutputContains('verified');
});

test('a failing sail check throws with the trimmed error output', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: "  the mysql container is not running \n")]);

    $context = new ServeContext(new ServeOptions, $this->sailServer);

    ($this->step)()->handle($context, fn ($passed) => $passed);
})->throws(DatabaseException::class, 'the mysql container is not running');
