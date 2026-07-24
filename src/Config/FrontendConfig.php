<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Illuminate\Contracts\Config\Repository;
use Igne\LaravelBootUp\Enums\PackageManager;

final readonly class FrontendConfig
{
    public function __construct(
        public PackageManager $packageManager,
        public string $assets,
        public string $watchIn,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            packageManager: PackageManager::from((string) $config->get('boot-up.frontend.package_manager', 'bun')),
            assets: (string) $config->get('boot-up.frontend.assets', 'watch'),
            watchIn: (string) $config->get('boot-up.frontend.watch_in', 'terminal'),
        );
    }
}
