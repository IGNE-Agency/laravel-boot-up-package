<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Environment\Steps\GenerateAppKey;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-app-key-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    $this->envFile = new EnvFile($this->dir.'/.env', $this->dir.'/.env.example');

    // Process::fake() must run before the Factory is resolved.
    $this->step = fn (): GenerateAppKey => new GenerateAppKey($this->envFile, new ProcessRunner(
        processes: app(Factory::class),
        ledger: new ProcessLedger($this->dir.'/processes.json'),
        logDirectory: $this->dir.'/logs',
    ));

    Prompt::fake();
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

test('generates a key when APP_KEY is empty', function (): void {
    file_put_contents($this->dir.'/.env', "APP_ENV=local\nAPP_KEY=\n");
    Process::fake(['*' => Process::result()]);

    $context = new BootContext(new BootOptions);

    expect(($this->step)()->handle($context, fn ($passed) => $passed))->toBe($context);

    Process::assertRan(function ($process): bool {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return $command === 'php artisan key:generate --ansi';
    });
});

test('generates a key when APP_KEY is absent entirely', function (): void {
    file_put_contents($this->dir.'/.env', "APP_ENV=local\n");
    Process::fake(['*' => Process::result()]);

    ($this->step)()->handle(new BootContext(new BootOptions), fn ($passed) => $passed);

    Process::assertRanTimes(fn ($process): bool => true, 1);
});

test('skips generation when APP_KEY is already set', function (): void {
    file_put_contents($this->dir.'/.env', "APP_ENV=local\nAPP_KEY=base64:abcdef\n");
    Process::fake();

    $context = new BootContext(new BootOptions);

    expect(($this->step)()->handle($context, fn ($passed) => $passed))->toBe($context);

    Process::assertNothingRan();
});
