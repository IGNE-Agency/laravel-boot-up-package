<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Tools\Fixtures;

use Igne\LaravelBootUp\Data\VersionConstraint;

/**
 * Reports Yarn 1 and records the constraint an update was asked to meet.
 */
final class YarnToolSpy extends EnsureToolsReadySpy
{
    public static ?string $updatedFor = null;

    public function id(): string
    {
        return 'yarn';
    }

    public function installedVersion(): ?string
    {
        return '1.22.22';
    }

    public function update(VersionConstraint $constraint): void
    {
        self::$updatedFor = $constraint->value;
    }
}
