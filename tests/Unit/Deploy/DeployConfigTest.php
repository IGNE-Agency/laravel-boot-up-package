<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Deploy\DeployConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the bootstrap.deploy schema', function (): void {
    $config = new Repository([
        'bootstrap' => [
            'deploy' => [
                'cache_framework_files' => true,
                'finalize' => ['storage:link', 'auth:clear-resets'],
            ],
        ],
    ]);

    $deploy = DeployConfig::fromRepository($config);

    expect($deploy->cacheFrameworkFiles)->toBeTrue()
        ->and($deploy->finalize)->toBe(['storage:link', 'auth:clear-resets']);
});

test('fromRepository falls back to the documented defaults', function (): void {
    $deploy = DeployConfig::fromRepository(new Repository);

    expect($deploy->cacheFrameworkFiles)->toBeFalse()
        ->and($deploy->finalize)->toBe(['storage:link']);
});
