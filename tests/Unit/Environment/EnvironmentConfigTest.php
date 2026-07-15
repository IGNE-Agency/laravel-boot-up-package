<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Environment\EnvironmentConfig;
use Illuminate\Config\Repository;

test('defaults allow local and development and manage the sail alias', function (): void {
    $config = EnvironmentConfig::fromRepository(new Repository([]));

    expect($config->allowedEnvironments)->toBe(['local', 'development'])
        ->and($config->manageSailAlias)->toBeTrue();
});

test('fromRepository reads the bootstrap environment keys', function (): void {
    $config = EnvironmentConfig::fromRepository(new Repository([
        'bootstrap' => [
            'environments' => ['local', 'staging'],
            'environment' => ['manage_sail_alias' => false],
        ],
    ]));

    expect($config->allowedEnvironments)->toBe(['local', 'staging'])
        ->and($config->manageSailAlias)->toBeFalse();
});
