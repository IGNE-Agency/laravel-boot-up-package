<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\EnvironmentConfig;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Environment\LocalEnvironment;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-local-environment-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    $this->envFile = new EnvFile($this->dir.'/.env', $this->dir.'/.env.example');

    // Empty server vars so a CI box reached over SSH does not trip the probe.
    $this->environment = fn (?EnvironmentConfig $config = null, ?array $serverVars = []): LocalEnvironment => new LocalEnvironment(
        $this->envFile,
        $config ?? new EnvironmentConfig,
        $serverVars,
    );
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

test('a missing or empty APP_ENV counts as local', function (): void {
    expect(($this->environment)()->name())->toBe('local');

    file_put_contents($this->dir.'/.env', "APP_ENV=\n");

    expect(($this->environment)()->name())->toBe('local');

    file_put_contents($this->dir.'/.env', "APP_ENV=staging\n");

    expect(($this->environment)()->name())->toBe('staging');
});

test('isAllowed checks the name against the configured list', function (): void {
    file_put_contents($this->dir.'/.env', "APP_ENV=staging\n");

    expect(($this->environment)()->isAllowed())->toBeFalse()
        ->and(($this->environment)(new EnvironmentConfig(['staging']))->isAllowed())->toBeTrue();
});

test('allowed exposes the configured environments for messages', function (): void {
    expect(($this->environment)()->allowed())->toBe(['local', 'development']);
});

test('isRemoteHost spots any of the SSH variables', function (): void {
    expect(($this->environment)()->isRemoteHost())->toBeFalse()
        ->and(($this->environment)(null, ['SSH_CLIENT' => '1.2.3.4'])->isRemoteHost())->toBeTrue()
        ->and(($this->environment)(null, ['SSH_TTY' => '/dev/ttys0'])->isRemoteHost())->toBeTrue()
        ->and(($this->environment)(null, ['SSH_CONNECTION' => '1.2.3.4 22'])->isRemoteHost())->toBeTrue();
});
