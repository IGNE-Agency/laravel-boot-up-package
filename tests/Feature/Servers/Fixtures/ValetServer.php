<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures;

use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Servers\CommandRewrites;
use Igne\LaravelBootUp\Servers\Server;

/**
 * A project-registered custom driver, as the extension API allows.
 */
final class ValetServer implements Server
{
    public function key(): string
    {
        return 'valet';
    }

    public function label(): string
    {
        return 'Laravel Valet';
    }

    public function requiredTools(): array
    {
        return [];
    }

    public function commandRewrites(): CommandRewrites
    {
        return CommandRewrites::none();
    }

    public function providesDatabase(): bool
    {
        return false;
    }

    public function databaseReachableFromHost(): bool
    {
        return true;
    }

    public function stopImpact(): ?string
    {
        return null;
    }

    public function isRunning(): bool
    {
        return false;
    }

    public function start(ServeContext $context): void {}

    public function stop(): void {}

    public function url(): string
    {
        return 'http://valet.test';
    }
}
