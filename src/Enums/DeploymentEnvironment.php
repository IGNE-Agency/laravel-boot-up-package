<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

enum DeploymentEnvironment: string
{
    case Development = 'development';
    case Staging = 'staging';
    case Production = 'production';

    public function includeDevDependencies(): bool
    {
        return $this === self::Development;
    }

    /**
     * Framework caching (`artisan optimize`) breaks env() lookups, so it is
     * reserved for staging and production scripts.
     */
    public function optimize(): bool
    {
        return $this !== self::Development;
    }
}
