<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\ProcessConfig;
use Igne\LaravelBootUp\Exceptions\ConfigException;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.process schema', function (): void {
    $config = ProcessConfig::fromRepository(new Repository([
        'boot-up' => [
            'process' => [
                'term_grace_seconds' => 9,
                'kill_grace_seconds' => 4,
                'install_timeout_seconds' => 60,
            ],
        ],
    ]));

    expect($config->termGraceSeconds)->toBe(9)
        ->and($config->killGraceSeconds)->toBe(4)
        ->and($config->installTimeoutSeconds)->toBe(60);
});

test('a grace period of zero is allowed — signal and do not wait', function (): void {
    $config = new Repository(['boot-up' => ['process' => ['term_grace_seconds' => 0]]]);

    expect(ProcessConfig::fromRepository($config)->termGraceSeconds)->toBe(0);
});

test('a negative grace period is rejected', function (): void {
    $config = new Repository(['boot-up' => ['process' => ['kill_grace_seconds' => -1]]]);

    expect(fn () => ProcessConfig::fromRepository($config))
        ->toThrow(ConfigException::class, 'boot-up.process.kill_grace_seconds');
});

test('an install timeout of zero is rejected, because no install finishes instantly', function (): void {
    $config = new Repository(['boot-up' => ['process' => ['install_timeout_seconds' => 0]]]);

    expect(fn () => ProcessConfig::fromRepository($config))
        ->toThrow(ConfigException::class, 'boot-up.process.install_timeout_seconds');
});
