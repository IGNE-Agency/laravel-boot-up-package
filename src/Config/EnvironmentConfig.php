<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Illuminate\Contracts\Config\Repository;

final readonly class EnvironmentConfig
{
    /**
     * @param  list<string>  $allowedEnvironments
     */
    public function __construct(
        public array $allowedEnvironments = ['local', 'development'],
        public bool $manageSailAlias = true,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            allowedEnvironments: (array) $config->get('boot-up.environments', ['local', 'development']),
            manageSailAlias: (bool) $config->get('boot-up.environment.manage_sail_alias', true),
        );
    }
}
