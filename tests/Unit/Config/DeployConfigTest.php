<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\DeployConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.deploy schema', function (): void {
    $deploy = DeployConfig::fromRepository(new Repository([
        'boot-up' => [
            'deploy' => [
                'cache_framework_files' => true,
                'finalize' => ['storage:link', 'auth:clear-resets'],
                'steps' => ['StepC'],
                'auto_accept' => true,
            ],
        ],
    ]));

    expect($deploy->cacheFrameworkFiles)->toBeTrue()
        ->and($deploy->finalize)->toBe(['storage:link', 'auth:clear-resets'])
        ->and($deploy->steps)->toBe(['StepC'])
        ->and($deploy->autoAccept)->toBeTrue();
});
