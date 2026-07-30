<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures;

use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\ServeContext;

/**
 * A project-registered custom driver, as the extension API allows: the
 * core Server contract is all a minimal driver needs.
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
