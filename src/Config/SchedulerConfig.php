<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Enums\RunMode;
use Illuminate\Contracts\Config\Repository;

/**
 * Off by default: schedule:work on a project with no scheduled tasks is
 * pure noise.
 */
final readonly class SchedulerConfig
{
    public function __construct(
        public bool $enabled = false,
        public RunMode $runIn = RunMode::Combined,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            enabled: (bool) $config->get('boot-up.scheduler.enabled', false),
            runIn: RunMode::fromConfig((string) $config->get('boot-up.scheduler.run_in', 'combined')),
        );
    }
}
