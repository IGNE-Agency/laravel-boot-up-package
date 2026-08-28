<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Enums\PackageManager;
use Illuminate\Contracts\Config\Repository;

final readonly class FrontendConfig
{
    public AssetMode $assets;

    /**
     * A null package manager means "not explicitly configured" — the
     * selector then detects one from the project's lockfile before falling
     * back to the default.
     */
    public function __construct(
        public ?PackageManager $packageManager = null,
        ?AssetMode $assets = null,
    ) {
        $this->assets = $assets ?? AssetMode::default();
    }

    public static function fromRepository(Repository $config): self
    {
        return new self(
            packageManager: PackageManager::fromConfigOrNull($config->get('boot-up.frontend.package_manager'), 'boot-up.frontend.package_manager'),
            assets: AssetMode::fromConfig($config->get('boot-up.frontend.assets'), 'boot-up.frontend.assets'),
        );
    }
}
