<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

use Igne\LaravelBootUp\Contracts\RewritesCommands;
use Igne\LaravelBootUp\Contracts\Server;

/**
 * The single passable travelling through the serve/deploy pipelines.
 * The server is null for app:deploy runs (no server is booted there).
 */
final class ServeContext
{
    public bool $serverWasAlreadyRunning = false;

    public function __construct(
        public readonly ServeOptions $options,
        public readonly ?Server $server = null,
    ) {}

    /**
     * The active server's command rewrites, or null when there is no
     * server or it runs commands as-is.
     */
    public function commandRewrites(): ?CommandRewrites
    {
        return $this->server instanceof RewritesCommands
            ? $this->server->commandRewrites()
            : null;
    }
}
