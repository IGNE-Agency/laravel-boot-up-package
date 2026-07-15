<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Servers\Artisan\ArtisanServer;
use Igne\LaravelBootUp\Servers\Herd\HerdServer;
use Igne\LaravelBootUp\Servers\Sail\SailServer;
use Igne\LaravelBootUp\Servers\ServersConfig;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ValetServer;
use Illuminate\Config\Repository;

test('defaults map the built-in drivers and prompt behaviour', function (): void {
    $config = ServersConfig::fromRepository(new Repository([]));

    expect($config->default)->toBeNull()
        ->and($config->prompt)->toBeTrue()
        ->and($config->drivers)->toBe([
            'herd' => HerdServer::class,
            'sail' => SailServer::class,
            'laravel' => ArtisanServer::class,
        ])
        ->and($config->promptStopServer)->toBeTrue()
        ->and($config->stopServerByDefault)->toBeFalse();
});

test('fromRepository reads the server and shutdown keys', function (): void {
    $config = ServersConfig::fromRepository(new Repository([
        'boot-up' => [
            'server' => ['default' => 'sail', 'prompt' => false],
            'shutdown' => ['prompt_stop_server' => false, 'stop_server_by_default' => true],
        ],
    ]));

    expect($config->default)->toBe('sail')
        ->and($config->prompt)->toBeFalse()
        ->and($config->promptStopServer)->toBeFalse()
        ->and($config->stopServerByDefault)->toBeTrue();
});

test('project drivers merge over the built-ins', function (): void {
    $config = ServersConfig::fromRepository(new Repository([
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
        ->and($config->drivers['laravel'])->toBe(ArtisanServer::class);
});
