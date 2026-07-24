<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Illuminate\Contracts\Config\Repository;

final readonly class WorkersConfig
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
            schedulerEnabled: (bool) $config->get('boot-up.workers.scheduler.enabled', false),
            schedulerRunIn: (string) $config->get('boot-up.workers.scheduler.run_in', 'terminal'),
            horizonEnabled: (bool) $config->get('boot-up.workers.horizon.enabled', true),
            horizonRunIn: (string) $config->get('boot-up.workers.horizon.run_in', 'terminal'),
            reverbEnabled: (bool) $config->get('boot-up.workers.reverb.enabled', true),
            reverbRunIn: (string) $config->get('boot-up.workers.reverb.run_in', 'terminal'),
        );
    }
}
