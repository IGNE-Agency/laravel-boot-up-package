<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Illuminate\Contracts\Config\Repository;

final readonly class EnvironmentConfig
{
    /**
     * @param  list<string>  $allowed  APP_ENV values app:serve may run under
     */
    public function __construct(
        public array $allowed = ['local', 'development'],
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            allowed: (array) $config->get('boot-up.environment.allowed', ['local', 'development']),
        );
    }
}
