<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\EnvironmentConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.environment schema', function (): void {
    $config = EnvironmentConfig::fromRepository(new Repository([
        'boot-up' => [
            'environment' => ['allowed' => ['local', 'staging']],
        ],
    ]));

    expect($config->allowed)->toBe(['local', 'staging']);
});
