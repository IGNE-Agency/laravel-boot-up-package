<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Illuminate\Contracts\Config\Repository;

final readonly class ShutdownConfig
{
    public function __construct(
        public bool $promptStopServer = true,
        public bool $stopServerByDefault = false,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            promptStopServer: (bool) $config->get('boot-up.shutdown.prompt_stop_server', true),
            stopServerByDefault: (bool) $config->get('boot-up.shutdown.stop_server_by_default', false),
        );
    }
}
