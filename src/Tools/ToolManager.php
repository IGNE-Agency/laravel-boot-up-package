<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

/**
 * Pure policy: decides whether a tool must be installed, updated, or left
 * alone. A version we cannot read or satisfy never blocks the boot.
 */
final class ToolManager
{
    public function __construct(
        private readonly ToolsConfig $config,
    ) {}

    public function ensure(InstallsTool $tool, VersionConstraint $constraint): void
    {
        if (! $tool->isInstalled()) {
            $this->installMissing($tool, $constraint);

            return;
        }

        if ($constraint->isWildcard()) {
            info("{$tool->label()} is installed.");

            return;
        }

        $version = $tool->installedVersion();

        if ($version === null) {
            warning("Could not determine the installed {$tool->label()} version; continuing.");

            return;
        }

        if ($constraint->isSatisfiedBy($version)) {
            info("{$tool->label()} {$version} satisfies '{$constraint->value}'.");

            return;
        }

        $this->updateOutdated($tool, $constraint, $version);
    }

    private function installMissing(InstallsTool $tool, VersionConstraint $constraint): void
    {
        if (! $this->config->autoInstall && ! confirm("{$tool->label()} is not installed. Install it now?")) {
            throw ToolException::notInstalled($tool->label());
        }

        info("{$tool->label()} not found. Installing...");
        $tool->install($constraint);
        info("{$tool->label()} installed.");
    }

    private function updateOutdated(InstallsTool $tool, VersionConstraint $constraint, string $version): void
    {
        if ($tool->updatesAutomatically()) {
            note("{$tool->label()} {$version} does not satisfy '{$constraint->value}', but it updates itself — skipping.");

            return;
        }

        if (! $this->config->autoUpdate) {
            warning("{$tool->label()} {$version} does not satisfy '{$constraint->value}'. Update it manually or enable boot-up.tools.auto_update.");

            return;
        }

        info("{$tool->label()} {$version} does not satisfy '{$constraint->value}'. Updating...");
        $tool->update($constraint);
        info("{$tool->label()} updated.");
    }
}
