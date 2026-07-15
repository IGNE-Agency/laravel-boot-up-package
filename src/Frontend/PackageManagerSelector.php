<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Frontend;

use function Laravel\Prompts\warning;

final class PackageManagerSelector
{
    public function __construct(
        private readonly FrontendConfig $config,
        private readonly PackageJson $packageJson,
    ) {}

    /**
     * The configured package manager, unless the project's package.json
     * pins another one via a "please-use-{manager}" engines sentinel.
     */
    public function selected(): PackageManager
    {
        $configured = $this->config->packageManager;
        $demanded = $this->packageJson->demandedPackageManager();

        if ($demanded !== null && $demanded !== $configured) {
            warning("package.json demands {$demanded->value}; using it instead of the configured {$configured->value}.");

            return $demanded;
        }

        return $configured;
    }
}
