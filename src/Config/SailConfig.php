<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Illuminate\Contracts\Config\Repository;

final readonly class SailConfig
{
    public function __construct(
        public bool $manageAlias = true,
        public int $readyTimeoutSeconds = 120,
        public int $dockerStartTimeoutSeconds = 60,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            manageAlias: (bool) $config->get('boot-up.sail.manage_alias', true),
            readyTimeoutSeconds: (int) $config->get('boot-up.sail.ready_timeout_seconds', 120),
            dockerStartTimeoutSeconds: (int) $config->get('boot-up.sail.docker.start_timeout_seconds', 60),
        );
    }
}
