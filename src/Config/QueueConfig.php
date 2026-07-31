<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Enums\RunMode;
use Illuminate\Contracts\Config\Repository;

final readonly class QueueConfig
{
    public RunMode $runIn;

    /**
     * @param  array<int|string, int|string|bool|null>  $flags  extra queue:work options, e.g. ['--tries' => 3]
     */
    public function __construct(
        public bool $enabled = true,
        ?RunMode $runIn = null,
        public array $flags = [],
    ) {
        $this->runIn = $runIn ?? RunMode::default();
    }

    public static function fromRepository(Repository $config): self
    {
        return new self(
            enabled: (bool) $config->get('boot-up.queue.enabled', true),
            runIn: RunMode::fromConfig($config->get('boot-up.queue.run_in'), 'boot-up.queue.run_in'),
            flags: (array) $config->get('boot-up.queue.flags', []),
        );
    }
}
