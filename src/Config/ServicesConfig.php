<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Illuminate\Contracts\Config\Repository;

final readonly class ServicesConfig
{
    public function __construct(
        public bool $schedulerEnabled = false,
        public string $schedulerRunIn = 'terminal',
        public bool $horizonEnabled = true,
        public string $horizonRunIn = 'terminal',
        public bool $reverbEnabled = true,
        public string $reverbRunIn = 'terminal',
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            schedulerEnabled: (bool) $config->get('boot-up.services.scheduler.enabled', false),
            schedulerRunIn: (string) $config->get('boot-up.services.scheduler.run_in', 'terminal'),
            horizonEnabled: (bool) $config->get('boot-up.services.horizon.enabled', true),
            horizonRunIn: (string) $config->get('boot-up.services.horizon.run_in', 'terminal'),
            reverbEnabled: (bool) $config->get('boot-up.services.reverb.enabled', true),
            reverbRunIn: (string) $config->get('boot-up.services.reverb.run_in', 'terminal'),
        );
    }
}
