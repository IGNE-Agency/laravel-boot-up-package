<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Serve\Fixtures;

use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Servers\CommandRewrites;
use Igne\LaravelBootUp\Servers\Server;

/**
 * A controllable driver double for shutdown/console tests. Register the
 * instance with app()->instance(RecordingServer::class, $double) so the
 * selector resolves this exact object.
 */
final class RecordingServer implements Server
{
    public int $starts = 0;

    public int $stops = 0;

    public function __construct(public bool $running = true) {}

    public function key(): string
    {
        return 'double';
    }

    public function label(): string
    {
        return 'Double Server';
    }

    public function requiredTools(): array
    {
        return [];
    }

    public function commandRewrites(): CommandRewrites
    {
        return CommandRewrites::none();
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function start(ServeContext $context): void
    {
        $this->starts++;
        $this->running = true;
    }

    public function stop(): void
    {
        $this->stops++;
        $this->running = false;
    }

    public function url(): string
    {
        return 'http://double.test';
    }
}
