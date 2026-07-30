<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Tools\Fixtures;

use Igne\LaravelBootUp\Contracts\InstallsTool;
use Igne\LaravelBootUp\Data\VersionConstraint;

final class RegistryCustomToolSpy implements InstallsTool
{
    public function id(): string
    {
        return 'php';
    }

    public function label(): string
    {
        return 'Custom PHP';
    }

    public function isInstalled(): bool
    {
        return true;
    }

    public function installedVersion(): ?string
    {
        return '8.3.0';
    }

    public function install(VersionConstraint $constraint): void {}

    public function update(VersionConstraint $constraint): void {}

    public function updatesAutomatically(): bool
    {
        return false;
    }
}
