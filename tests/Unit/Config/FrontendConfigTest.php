<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Enums\RunMode;
use Illuminate\Config\Repository;

test('fromRepository reads the boot-up.frontend schema', function (): void {
    $config = FrontendConfig::fromRepository(new Repository([
        'boot-up' => [
            'frontend' => ['package_manager' => 'npm', 'assets' => 'build', 'watch_in' => 'terminal'],
        ],
    ]));

    expect($config->packageManager)->toBe(PackageManager::NPM)
        ->and($config->assets)->toBe(AssetMode::Build)
        ->and($config->watchIn)->toBe(RunMode::Terminal);
});
