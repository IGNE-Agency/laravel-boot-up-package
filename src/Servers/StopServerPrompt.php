<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

use function Laravel\Prompts\confirm;

/**
 * Asks (config-gated) whether shutdown should stop a server that
 * app:serve itself started.
 */
final class StopServerPrompt
{
    public function __construct(private readonly ServersConfig $config) {}

    public function shouldStop(Server $server): bool
    {
        if (! $this->config->promptStopServer) {
            return $this->config->stopServerByDefault;
        }

        return confirm(
            label: "Stop {$server->label()}? Other projects may be using it.",
            default: $this->config->stopServerByDefault,
        );
    }
}
