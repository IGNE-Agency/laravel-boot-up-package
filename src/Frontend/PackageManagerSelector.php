<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Frontend;

final class PackageManagerSelector
{
    private ?PackageManager $selected = null;

    public function __construct(
        private readonly FrontendConfig $config,
        private readonly PackageJson $packageJson,
    ) {}

    /**
     * The configured package manager, unless the project's package.json
     * pins another one via a "please-use-{manager}" engines sentinel.
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

        if ($demanded !== null && $demanded !== $configured) {
            terminal()->warning("package.json demands {$demanded->value}; using it instead of the configured {$configured->value}.");

            return $demanded;
        }

        return $configured;
    }
}
