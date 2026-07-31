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
    public RunMode $runIn;

    public function __construct(
        public bool $enabled = false,
        ?RunMode $runIn = null,
    ) {
        $this->runIn = $runIn ?? RunMode::default();
    }

    public static function fromRepository(Repository $config): self
    {
        return new self(
            enabled: (bool) $config->get('boot-up.scheduler.enabled', false),
            runIn: RunMode::fromConfig($config->get('boot-up.scheduler.run_in'), 'boot-up.scheduler.run_in'),
        );
    }
}
