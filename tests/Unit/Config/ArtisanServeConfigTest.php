<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\ArtisanServeConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.artisan schema', function (): void {
    $config = ArtisanServeConfig::fromRepository(new Repository([
        'boot-up' => [
            'artisan' => ['host' => '0.0.0.0', 'port' => 8080],
        ],
    ]));

    expect($config->host)->toBe('0.0.0.0')
        ->and($config->port)->toBe(8080);
});
