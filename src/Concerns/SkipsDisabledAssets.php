<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Enums\AssetMode;

/**
 * The gates every asset step shares: the --without-assets flag, the
 * configured AssetMode::Skip, and a project with no package.json.
 * Expects the using class to carry FrontendConfig $config and
 * PackageJson $packageJson.
 */
trait SkipsDisabledAssets
{
    /**
     * The note explaining why asset handling is skipped entirely, or null.
     */
    private function sharedAssetSkipReason(BootContext $context): ?string
    {
        return match (true) {
            ! $context->options->withAssets => 'Assets skipped (--without-assets).',
            $this->config->assets === AssetMode::Skip => 'Assets disabled in configuration — skipping.',
            ! $this->packageJson->exists() => 'No package.json found — skipping assets.',
            default => null,
        };
    }
}
