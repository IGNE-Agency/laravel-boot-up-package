<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Environment\EnvFile;
use Igne\LaravelBootstrap\Environment\Steps\GenerateAppKey;
use Igne\LaravelBootstrap\Process\ProcessLedger;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\Terminal\NullTerminal;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\ServeOptions;
use Igne\LaravelBootstrap\Support\Poller;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bootstrap-app-key-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    $this->envFile = new EnvFile($this->dir.'/.env', $this->dir.'/.env.example');

    // Process::fake() must run before the Factory is resolved.
    $this->step = fn (): GenerateAppKey => new GenerateAppKey($this->envFile, new ProcessRunner(
        processes: app(Factory::class),
        ledger: new ProcessLedger($this->dir.'/processes.json'),
        terminal: new NullTerminal,
        poller: new Poller,
        logDirectory: $this->dir.'/logs',
        runtimeDirectory: $this->dir.'/runtime',
    ));

    Prompt::fake();
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

test('generates a key when APP_KEY is empty', function (): void {
    file_put_contents($this->dir.'/.env', "APP_ENV=local\nAPP_KEY=\n");
    Process::fake(['*' => Process::result()]);

    $context = new ServeContext(new ServeOptions);

    expect(($this->step)()->handle($context, fn ($passed) => $passed))->toBe($context);

    Process::assertRan(function ($process): bool {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return $command === 'php artisan key:generate --ansi';
    });
});

test('generates a key when APP_KEY is absent entirely', function (): void {
    file_put_contents($this->dir.'/.env', "APP_ENV=local\n");
    Process::fake(['*' => Process::result()]);

    ($this->step)()->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertRanTimes(fn ($process): bool => true, 1);
});

test('skips generation when APP_KEY is already set', function (): void {
    file_put_contents($this->dir.'/.env', "APP_ENV=local\nAPP_KEY=base64:abcdef\n");
    Process::fake();

    $context = new ServeContext(new ServeOptions);

    expect(($this->step)()->handle($context, fn ($passed) => $passed))->toBe($context);

    Process::assertNothingRan();
});
