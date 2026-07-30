<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\DevServerConfig;
use Igne\LaravelBootUp\Servers\Artisan\ArtisanServer;
use Igne\LaravelBootUp\Servers\Sail\SailServer;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ValetServer;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.server schema', function (): void {
    $config = DevServerConfig::fromRepository(new Repository([
        'boot-up' => [
            'server' => ['default' => 'sail', 'prompt' => false],
        ],
    ]));

    expect($config->default)->toBe('sail')
        ->and($config->prompt)->toBeFalse();
});

test('project drivers merge over the built-ins', function (): void {
    $config = DevServerConfig::fromRepository(new Repository([
        'boot-up' => [
            'server' => [
                'drivers' => [
                    'valet' => ValetServer::class,
                    'herd' => ValetServer::class,
                ],
            ],
        ],
    ]));

    expect($config->drivers['valet'])->toBe(ValetServer::class)
        ->and($config->drivers['herd'])->toBe(ValetServer::class)
        ->and($config->drivers['sail'])->toBe(SailServer::class)
        ->and($config->drivers['artisan'])->toBe(ArtisanServer::class);
});
