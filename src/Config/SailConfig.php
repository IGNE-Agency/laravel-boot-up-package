<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Concerns\ValidatesConfig;
use Illuminate\Contracts\Config\Repository;

final readonly class SailConfig
{
    use ValidatesConfig;

    public function __construct(
        public bool $manageAlias = true,
        public int $readyTimeoutSeconds = 120,
        public int $dockerStartTimeoutSeconds = 60,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            manageAlias: (bool) $config->get('boot-up.sail.manage_alias', true),
            readyTimeoutSeconds: self::atLeast($config->get('boot-up.sail.ready_timeout_seconds', 120), 1, 'boot-up.sail.ready_timeout_seconds'),
            dockerStartTimeoutSeconds: self::atLeast($config->get('boot-up.sail.docker.start_timeout_seconds', 60), 1, 'boot-up.sail.docker.start_timeout_seconds'),
        );
    }
}
