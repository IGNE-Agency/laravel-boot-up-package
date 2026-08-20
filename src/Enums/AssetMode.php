<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

use Igne\LaravelBootUp\Concerns\ResolvesFromConfig;

/**
 * What the boot does about frontend assets: keep a watcher running,
 * build them once, or leave them alone entirely.
 */
enum AssetMode: string
{
    use ResolvesFromConfig;

    case Watch = 'watch';
    case Build = 'build';
    case Skip = 'skip';

    public static function default(): self
    {
        return self::Watch;
    }
}
