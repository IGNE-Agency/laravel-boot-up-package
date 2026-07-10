<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Database\DatabaseConfig;
use Illuminate\Config\Repository;

test('fromRepository reads the bootstrap.database and bootstrap.migrations schema', function (): void {
    $config = new Repository([
        'bootstrap' => [
            'database' => [
                'create' => false,
                'prompt_missing_credentials' => false,
            ],
            'migrations' => [
                'auto' => false,
            ],
        ],
    ]);

    $database = DatabaseConfig::fromRepository($config);

    expect($database->create)->toBeFalse()
        ->and($database->promptMissingCredentials)->toBeFalse()
        ->and($database->migrationsAuto)->toBeFalse();
});

test('fromRepository falls back to the documented defaults', function (): void {
    $database = DatabaseConfig::fromRepository(new Repository);

    expect($database->create)->toBeTrue()
        ->and($database->promptMissingCredentials)->toBeTrue()
        ->and($database->migrationsAuto)->toBeTrue();
});
