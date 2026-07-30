<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\SailConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.sail schema', function (): void {
    $config = SailConfig::fromRepository(new Repository([
        'boot-up' => [
            'sail' => [
                'manage_alias' => false,
                'ready_timeout_seconds' => 30,
                'docker' => ['start_timeout_seconds' => 10],
            ],
        ],
    ]));

    expect($config->manageAlias)->toBeFalse()
        ->and($config->readyTimeoutSeconds)->toBe(30)
        ->and($config->dockerStartTimeoutSeconds)->toBe(10);
});
