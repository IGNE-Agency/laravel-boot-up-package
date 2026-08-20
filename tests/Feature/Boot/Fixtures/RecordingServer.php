<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Boot\Fixtures;

use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\BootContext;

/**
 * A controllable driver double for shutdown/console tests. Register the
 * instance with app()->instance(RecordingServer::class, $double) so the
 * selector resolves this exact object.
 */
final class RecordingServer implements Server
{
    public int $starts = 0;

    public int $stops = 0;

    public function __construct(
        public bool $running = true,
        public bool $stopThrows = false,
    ) {}

    public function key(): string
    {
        return 'double';
    }

    public function label(): string
    {
        return 'Double Server';
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function start(BootContext $context): void
    {
        $this->starts++;
        $this->running = true;
    }

    public function stop(): void
    {
        $this->stops++;

        if ($this->stopThrows) {
            throw new \RuntimeException('stop failed');
        }

        $this->running = false;
    }

    public function url(): string
    {
        return 'http://double.test';
    }
}
