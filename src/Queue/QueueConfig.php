<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Queue;

use Illuminate\Contracts\Config\Repository;

final readonly class QueueConfig
{
    /**
     * @param  array<int|string, int|string|bool|null>  $flags  extra queue:work options, e.g. ['--tries' => 3]
     */
    public function __construct(
        public bool $enabled = true,
        public string $runIn = 'background',
        public array $flags = [],
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            enabled: (bool) $config->get('bootstrap.queue.enabled', true),
            runIn: (string) $config->get('bootstrap.queue.run_in', 'background'),
            flags: (array) $config->get('bootstrap.queue.flags', []),
        );
    }
}
