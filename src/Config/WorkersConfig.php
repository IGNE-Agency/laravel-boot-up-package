<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Enums\RunMode;
use Illuminate\Contracts\Config\Repository;

final readonly class WorkersConfig
{
    public function __construct(
        public bool $schedulerEnabled = false,
        public RunMode $schedulerRunIn = RunMode::Combined,
        public bool $horizonEnabled = true,
        public RunMode $horizonRunIn = RunMode::Combined,
        public bool $reverbEnabled = true,
        public RunMode $reverbRunIn = RunMode::Combined,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            schedulerEnabled: (bool) $config->get('boot-up.workers.scheduler.enabled', false),
            schedulerRunIn: RunMode::fromConfig((string) $config->get('boot-up.workers.scheduler.run_in', 'combined')),
            horizonEnabled: (bool) $config->get('boot-up.workers.horizon.enabled', true),
            horizonRunIn: RunMode::fromConfig((string) $config->get('boot-up.workers.horizon.run_in', 'combined')),
            reverbEnabled: (bool) $config->get('boot-up.workers.reverb.enabled', true),
            reverbRunIn: RunMode::fromConfig((string) $config->get('boot-up.workers.reverb.run_in', 'combined')),
        );
    }
}
