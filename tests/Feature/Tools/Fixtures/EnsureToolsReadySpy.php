<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Tools\Fixtures;

use Igne\LaravelBootUp\Contracts\InstallsTool;
use Igne\LaravelBootUp\Data\VersionConstraint;

abstract class EnsureToolsReadySpy implements InstallsTool
{
    /** @var list<string> Every tool id ensure() touched, in order. */
    public static array $ensured = [];

    public function label(): string
    {
        return ucfirst($this->id());
    }

    public function isInstalled(): bool
    {
        self::$ensured[] = $this->id();

        return true;
    }

    public function installedVersion(): ?string
    {
        return '1.0.0';
    }

    public function install(VersionConstraint $constraint): void {}

    public function update(VersionConstraint $constraint): void {}

    public function updatesAutomatically(): bool
    {
        return false;
    }
}
