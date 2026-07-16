<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Services;

use Illuminate\Contracts\Config\Repository;

final readonly class ServicesConfig
{
    public function __construct(
        public bool $schedulerEnabled = false,
        public string $schedulerRunIn = 'background',
        public bool $horizonEnabled = true,
        public bool $reverbEnabled = true,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            schedulerEnabled: (bool) $config->get('boot-up.services.scheduler.enabled', false),
            schedulerRunIn: (string) $config->get('boot-up.services.scheduler.run_in', 'background'),
            horizonEnabled: (bool) $config->get('boot-up.services.horizon.enabled', true),
            reverbEnabled: (bool) $config->get('boot-up.services.reverb.enabled', true),
        );
    }
}
