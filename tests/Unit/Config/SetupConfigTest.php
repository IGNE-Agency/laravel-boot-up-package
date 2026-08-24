<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\SetupConfig;
use Igne\LaravelBootUp\Environment\Steps\EnsureEnvFile;
use Igne\LaravelBootUp\Servers\Steps\StartServer;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.setup schema', function (): void {
    $config = SetupConfig::fromRepository(new Repository([
        'boot-up' => [
            'setup' => [
                'steps' => [EnsureEnvFile::class, StartServer::class],
                'open_browser' => false,
                'auto_accept' => true,
            ],
        ],
    ]));

    expect($config->steps)->toBe([EnsureEnvFile::class, StartServer::class])
        ->and($config->openBrowser)->toBeFalse()
        ->and($config->autoAccept)->toBeTrue();
});

test('fromRepository falls back to the documented defaults', function (): void {
    $config = SetupConfig::fromRepository(new Repository);

    expect($config->steps)->toBe([])
        ->and($config->openBrowser)->toBeTrue()
        ->and($config->autoAccept)->toBeFalse();
});
