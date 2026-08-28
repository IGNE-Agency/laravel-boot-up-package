<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isMacos()
 * @method static bool isLinux()
 * @method static bool isWindows()
 *
 * @see \Igne\LaravelBootUp\Services\Platform
 */
final class Platform extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Igne\LaravelBootUp\Services\Platform::class;
    }
}
