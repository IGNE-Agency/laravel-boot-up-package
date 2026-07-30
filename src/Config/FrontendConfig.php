<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Enums\RunMode;
use Illuminate\Contracts\Config\Repository;

final readonly class FrontendConfig
{
    public function __construct(
        public PackageManager $packageManager = PackageManager::BUN,
        public AssetMode $assets = AssetMode::Watch,
        public RunMode $watchIn = RunMode::Combined,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            packageManager: PackageManager::fromConfig($config->get('boot-up.frontend.package_manager'), 'boot-up.frontend.package_manager', PackageManager::BUN),
            assets: AssetMode::fromConfig($config->get('boot-up.frontend.assets'), 'boot-up.frontend.assets', AssetMode::Watch),
            watchIn: RunMode::fromConfig($config->get('boot-up.frontend.watch_in'), 'boot-up.frontend.watch_in', RunMode::Combined),
        );
    }
}
