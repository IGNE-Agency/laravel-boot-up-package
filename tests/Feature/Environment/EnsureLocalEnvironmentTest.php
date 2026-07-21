<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Environment\EnvironmentConfig;
use Igne\LaravelBootUp\Environment\EnvironmentException;
use Igne\LaravelBootUp\Environment\Steps\EnsureLocalEnvironment;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\ServeOptions;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-local-env-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    $this->envFile = new EnvFile($this->dir.'/.env', $this->dir.'/.env.example');

    // Empty server vars so a CI box reached over SSH does not trip the guard.
    $this->step = fn (?EnvironmentConfig $config = null, ?array $serverVars = []): EnsureLocalEnvironment => new EnsureLocalEnvironment(
        $this->envFile,
        $config ?? new EnvironmentConfig,
        $serverVars,
    );
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

test('passes for the allowed environments', function (string $environment): void {
    file_put_contents($this->dir.'/.env', "APP_ENV={$environment}\n");

    $context = new ServeContext(new ServeOptions);

    expect(($this->step)()->handle($context, fn ($passed) => $passed))->toBe($context);
})->with(['local', 'development']);

test('a missing APP_ENV key counts as local and passes', function (): void {
    file_put_contents($this->dir.'/.env', "APP_NAME=Laravel\n");

    $context = new ServeContext(new ServeOptions);

    expect(($this->step)()->handle($context, fn ($passed) => $passed))->toBe($context);
});

test('an empty APP_ENV value counts as local and passes', function (): void {
    file_put_contents($this->dir.'/.env', "APP_ENV=\n");

    $context = new ServeContext(new ServeOptions);

    expect(($this->step)()->handle($context, fn ($passed) => $passed))->toBe($context);
});

test('throws for a production APP_ENV', function (): void {
    file_put_contents($this->dir.'/.env', "APP_ENV=production\n");

    ($this->step)()->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);
})->throws(EnvironmentException::class, 'production');

test('the rejection names the configured allowed environments', function (): void {
    file_put_contents($this->dir.'/.env', "APP_ENV=production\n");

    ($this->step)()->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);
})->throws(EnvironmentException::class, '[local, development]');

test('respects a customised allowed environments list', function (): void {
    file_put_contents($this->dir.'/.env', "APP_ENV=staging\n");

    $config = new EnvironmentConfig(allowedEnvironments: ['staging']);
    $context = new ServeContext(new ServeOptions);

    expect(($this->step)($config)->handle($context, fn ($passed) => $passed))->toBe($context);
});

test('throws when running over SSH', function (array $serverVars): void {
    file_put_contents($this->dir.'/.env', "APP_ENV=local\n");

    ($this->step)(null, $serverVars)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);
})->with([
    'SSH_CLIENT' => [['SSH_CLIENT' => '10.0.0.5 51000 22']],
    'SSH_TTY' => [['SSH_TTY' => '/dev/pts/0']],
    'SSH_CONNECTION' => [['SSH_CONNECTION' => '10.0.0.5 51000 10.0.0.1 22']],
])->throws(EnvironmentException::class, 'remote host');
