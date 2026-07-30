<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Enums\RunMode;
use Illuminate\Contracts\Config\Repository;

final readonly class HorizonConfig
{
    public function __construct(
        public bool $enabled = true,
        public RunMode $runIn = RunMode::Combined,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            enabled: (bool) $config->get('boot-up.horizon.enabled', true),
            runIn: RunMode::fromConfig($config->get('boot-up.horizon.run_in'), 'boot-up.horizon.run_in', RunMode::Combined),
        );
    }
}
