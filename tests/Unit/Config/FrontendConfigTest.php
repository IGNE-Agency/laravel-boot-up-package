<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Enums\PackageManager;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.frontend schema', function (): void {
    $config = FrontendConfig::fromRepository(new Repository([
        'boot-up' => [
            'frontend' => ['package_manager' => 'npm', 'assets' => 'build'],
        ],
    ]));

    expect($config->packageManager)->toBe(PackageManager::Npm)
        ->and($config->assets)->toBe(AssetMode::Build);
});

test('an unset package manager stays null so the selector can detect one', function (): void {
    expect(FrontendConfig::fromRepository(new Repository([]))->packageManager)->toBeNull()
        ->and((new FrontendConfig)->packageManager)->toBeNull();
});
