<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures;

/**
 * Host-style capability defaults for anonymous Server doubles; override
 * per test when the double plays a container server like Sail.
 */
trait DefaultServerCapabilities
{
    public function providesDatabase(): bool
    {
        return false;
    }

    public function databaseReachableFromHost(): bool
    {
        return true;
    }

    public function stopImpact(): ?string
    {
        return null;
    }
}
