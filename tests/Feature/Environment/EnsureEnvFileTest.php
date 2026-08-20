<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Environment\Steps\EnsureEnvFile;
use Igne\LaravelBootUp\Exceptions\EnvironmentException;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-env-step-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    $this->envPath = $this->dir.'/.env';
    $this->examplePath = $this->dir.'/.env.example';
    $this->app->instance(EnvFile::class, new EnvFile($this->envPath, $this->examplePath));

    Prompt::fake();
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

test('leaves an existing .env untouched and continues the pipeline', function (): void {
    file_put_contents($this->envPath, "APP_ENV=local\nAPP_KEY=base64:abc\n");

    $context = new BootContext(new BootOptions);
    $nextCalled = false;

    $result = app(EnsureEnvFile::class)->handle($context, function ($passed) use (&$nextCalled, $context) {
        $nextCalled = true;

        expect($passed)->toBe($context);

        return $passed;
    });

    expect($nextCalled)->toBeTrue()
        ->and($result)->toBe($context)
        ->and(file_get_contents($this->envPath))->toBe("APP_ENV=local\nAPP_KEY=base64:abc\n");
});

test('creates the .env from the example when missing', function (): void {
    file_put_contents($this->examplePath, "APP_NAME=Laravel\nAPP_ENV=local\n");

    $context = new BootContext(new BootOptions);

    $result = app(EnsureEnvFile::class)->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context)
        ->and(file_get_contents($this->envPath))->toBe("APP_NAME=Laravel\nAPP_ENV=local\n");
});

test('throws when neither .env nor .env.example exists', function (): void {
    app(EnsureEnvFile::class)->handle(new BootContext(new BootOptions), fn ($passed) => $passed);
})->throws(EnvironmentException::class, '.env.example');
