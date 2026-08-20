<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Frontend;

use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Enums\PackageManager;

final class PackageManagerSelector
{
    private ?PackageManager $selected = null;

    public function __construct(
        private readonly FrontendConfig $config,
        private readonly PackageJson $packageJson,
    ) {}

    /**
     * The package manager for this project, strongest signal first: a
     * "please-use-{manager}" engines sentinel in package.json, then the
     * explicit config value, then the lockfile on disk, then the default.
     * Memoized (and bound as a singleton) so the override warning prints
     * once per boot, not once per step that asks.
     */
    public function selected(): PackageManager
    {
        return $this->selected ??= $this->resolve();
    }

    private function resolve(): PackageManager
    {
        $configured = $this->config->packageManager;
        $demanded = $this->packageJson->demandedPackageManager();

        if ($demanded !== null) {
            if ($configured !== null && $demanded !== $configured) {
                terminal()->warning("package.json demands {$demanded->value}; using it instead of the configured {$configured->value}.");
            }

            return $demanded;
        }

        if ($configured !== null) {
            return $configured;
        }

        $locked = $this->packageJson->lockedPackageManager();

        if ($locked !== null) {
            $lockfile = $this->packageJson->matchedLockfile($locked) ?? $locked->lockfile();

            terminal()->note("Using {$locked->value} — detected from {$lockfile}.");

            return $locked;
        }

        return PackageManager::default();
    }
}
