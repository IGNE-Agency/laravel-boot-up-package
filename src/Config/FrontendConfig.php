<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Enums\RunMode;
use Illuminate\Contracts\Config\Repository;

final readonly class FrontendConfig
{
    public function __construct(
        public PackageManager $packageManager = PackageManager::BUN,
        public string $assets = 'watch',
        public RunMode $watchIn = RunMode::Combined,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            packageManager: PackageManager::from((string) $config->get('boot-up.frontend.package_manager', 'bun')),
            assets: (string) $config->get('boot-up.frontend.assets', 'watch'),
            watchIn: RunMode::fromConfig((string) $config->get('boot-up.frontend.watch_in', 'combined')),
        );
    }
}
