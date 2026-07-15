<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Frontend;

use Illuminate\Contracts\Config\Repository;

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
            packageManager: PackageManager::from((string) $config->get('bootstrap.frontend.package_manager', 'bun')),
            assets: (string) $config->get('bootstrap.frontend.assets', 'watch'),
            watchIn: (string) $config->get('bootstrap.frontend.watch_in', 'background'),
        );
    }
}
