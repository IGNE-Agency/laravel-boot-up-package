<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\HerdConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.herd schema', function (): void {
    $config = HerdConfig::fromRepository(new Repository([
        'boot-up' => [
            'herd' => [
                'site' => 'dashboard',
                'health' => ['attempts' => 3, 'delay_ms' => 100, 'timeout_seconds' => 2],
            ],
        ],
    ]));

    expect($config->site)->toBe('dashboard')
        ->and($config->healthAttempts)->toBe(3)
        ->and($config->healthDelayMs)->toBe(100)
        ->and($config->healthTimeoutSeconds)->toBe(2);
});
