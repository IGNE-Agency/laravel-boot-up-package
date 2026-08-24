<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\SetupConfig;
use Igne\LaravelBootUp\Environment\Steps\EnsureEnvFile;
use Igne\LaravelBootUp\Exceptions\ConfigException;
use Igne\LaravelBootUp\Servers\Steps\StartServer;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.setup schema', function (): void {
    $config = SetupConfig::fromRepository(new Repository([
        'boot-up' => [
            'setup' => [
                'steps' => [EnsureEnvFile::class, StartServer::class],
                'open_browser' => false,
                'browser' => ['wait_timeout_seconds' => 15, 'poll_interval_ms' => 250],
                'auto_accept' => true,
            ],
        ],
    ]));

    expect($config->steps)->toBe([EnsureEnvFile::class, StartServer::class])
        ->and($config->openBrowser)->toBeFalse()
        ->and($config->autoAccept)->toBeTrue()
        ->and($config->browserWaitTimeoutSeconds)->toBe(15)
        ->and($config->browserPollIntervalMs)->toBe(250);
});

test('a zero browser timeout is allowed, and means do not wait', function (): void {
    $config = SetupConfig::fromRepository(new Repository([
        'boot-up' => ['setup' => ['browser' => ['wait_timeout_seconds' => 0]]],
    ]));

    expect($config->browserWaitTimeoutSeconds)->toBe(0);
});

test('a poll interval below the floor is rejected rather than spun on', function (): void {
    expect(fn (): SetupConfig => SetupConfig::fromRepository(new Repository([
        'boot-up' => ['setup' => ['browser' => ['poll_interval_ms' => 0]]],
    ])))->toThrow(ConfigException::class);
});

test('fromRepository falls back to the documented defaults', function (): void {
    $config = SetupConfig::fromRepository(new Repository);

    expect($config->steps)->toBe([])
        ->and($config->openBrowser)->toBeTrue()
        ->and($config->autoAccept)->toBeFalse()
        ->and($config->browserWaitTimeoutSeconds)->toBe(60)
        ->and($config->browserPollIntervalMs)->toBe(500);
});
