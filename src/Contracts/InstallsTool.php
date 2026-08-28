<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

use Igne\LaravelBootUp\Data\VersionConstraint;

/**
 * A self-contained tool: it knows how to detect, install, and update itself.
 * Consuming projects can register their own implementations under
 * config('boot-up.tools.installers') — config wins over the built-ins.
 */
interface InstallsTool
{
    public function id(): string;

    public function label(): string;

    public function isInstalled(): bool;

    public function installedVersion(): ?string;

    public function install(VersionConstraint $constraint): void;

    public function update(VersionConstraint $constraint): void;

    /**
     * Tools with their own updater (GUI apps) are skipped by auto-update.
     */
    public function updatesAutomatically(): bool;
}
