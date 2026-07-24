<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools;

use Igne\LaravelBootUp\Config\ToolsConfig;
use Igne\LaravelBootUp\Contracts\InstallsTool;
use Igne\LaravelBootUp\Data\ToolOutcome;
use Igne\LaravelBootUp\Data\VersionConstraint;
use Igne\LaravelBootUp\Enums\ToolStatus;
use Igne\LaravelBootUp\Exceptions\ToolException;

/**
 * Pure policy: decides whether a tool must be installed, updated, or left
 * alone. A version we cannot read or satisfy never blocks the boot.
 * Satisfied tools stay quiet — the caller bundles their outcomes into one
 * summary; installs, updates and warnings print immediately.
 */
final class ToolManager
{
    public function __construct(
        private readonly ToolsConfig $config,
    ) {}

    public function ensure(InstallsTool $tool, VersionConstraint $constraint): ToolOutcome
    {
        if (! $tool->isInstalled()) {
            return $this->installMissing($tool, $constraint);
        }

        if ($constraint->isWildcard()) {
            return new ToolOutcome($tool->label(), ToolStatus::Satisfied);
        }

        $version = $tool->installedVersion();

        if ($version === null) {
            terminal()->warning("Could not determine the installed {$tool->label()} version; continuing.");

            return new ToolOutcome($tool->label(), ToolStatus::Unverified);
        }

        if ($constraint->isSatisfiedBy($version)) {
            return new ToolOutcome($tool->label(), ToolStatus::Satisfied, $version);
        }

        return $this->updateOutdated($tool, $constraint, $version);
    }

    private function installMissing(InstallsTool $tool, VersionConstraint $constraint): ToolOutcome
    {
        if (! $this->config->autoInstall && ! terminal()->confirm("{$tool->label()} is not installed. Install it now?")) {
            throw ToolException::notInstalled($tool->label());
        }

        terminal()->info("{$tool->label()} not found. Installing...");
        $tool->install($constraint);
        terminal()->success("{$tool->label()} installed.");

        return new ToolOutcome($tool->label(), ToolStatus::Installed);
    }

    private function updateOutdated(InstallsTool $tool, VersionConstraint $constraint, string $version): ToolOutcome
    {
        if ($tool->updatesAutomatically()) {
            terminal()->note("{$tool->label()} {$version} does not satisfy '{$constraint->value}', but it updates itself — skipping.");

            return new ToolOutcome($tool->label(), ToolStatus::SkippedSelfUpdating, $version);
        }

        if (! $this->config->autoUpdate) {
            terminal()->warning("{$tool->label()} {$version} does not satisfy '{$constraint->value}'. Update it manually or enable boot-up.tools.auto_update.");

            return new ToolOutcome($tool->label(), ToolStatus::Unverified, $version);
        }

        terminal()->info("{$tool->label()} {$version} does not satisfy '{$constraint->value}'. Updating...");
        $tool->update($constraint);

        $updated = $tool->installedVersion();

        if ($updated !== null && $constraint->isSatisfiedBy($updated)) {
            terminal()->success("{$tool->label()} updated to {$updated}.");

            return new ToolOutcome($tool->label(), ToolStatus::Updated, $updated);
        }

        terminal()->warning(
            "{$tool->label()} is ".($updated ?? 'an unreadable version')." after updating, which still does not satisfy '{$constraint->value}'. "
            .'The default install cannot provide it — install a matching version yourself (e.g. a versioned Homebrew formula) or relax the constraint. Continuing.'
        );

        return new ToolOutcome($tool->label(), ToolStatus::Unverified, $updated);
    }
}
