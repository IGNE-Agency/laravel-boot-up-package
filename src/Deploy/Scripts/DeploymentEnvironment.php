<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Deploy\Scripts;

enum DeploymentEnvironment: string
{
    case DEVELOPMENT = 'development';
    case STAGING = 'staging';
    case PRODUCTION = 'production';

    public function includeDevDependencies(): bool
    {
        return $this === self::DEVELOPMENT;
    }

    /**
     * Framework caching (`artisan optimize`) breaks env() lookups, so it is
     * reserved for staging and production scripts.
     */
    public function optimize(): bool
    {
        return $this !== self::DEVELOPMENT;
    }
}
