<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\DevConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.dev schema', function (): void {
    $config = DevConfig::fromRepository(new Repository([
        'boot-up' => ['dev' => ['logs' => false]],
    ]));

    expect($config->logs)->toBeFalse();
});

test('fromRepository falls back to the documented default', function (): void {
    expect(DevConfig::fromRepository(new Repository)->logs)->toBeTrue();
});
