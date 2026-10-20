<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

use Igne\LaravelBootUp\Concerns\ResolvesFromConfig;

/**
 * How fortrabbit distributes a build onto the environment: replace swaps
 * the code wholesale (minus the exclude patterns), merge copies it over
 * what is there and deletes only what the replace patterns name.
 */
enum FortrabbitDeployStrategy: string
{
    use ResolvesFromConfig;

    case Replace = 'replace';
    case Merge = 'merge';

    public static function default(): self
    {
        return self::Replace;
    }
}
