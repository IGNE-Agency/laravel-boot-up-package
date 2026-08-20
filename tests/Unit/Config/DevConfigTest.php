<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\DevConfig;
use Igne\LaravelBootUp\Environment\Steps\EnsureEnvFile;
use Igne\LaravelBootUp\Servers\Steps\StartServer;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.dev schema', function (): void {
    $config = DevConfig::fromRepository(new Repository([
        'boot-up' => [
            'dev' => [
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
