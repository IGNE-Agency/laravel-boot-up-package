<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

/**
 * Asks (config-gated) whether shutdown should stop a server that
 * app:serve itself started. A server whose stop reaches beyond this
 * project (stopImpact()) is never stopped without an explicit yes.
 */
final class StopServerPrompt
{
    public function __construct(private readonly ServersConfig $config) {}

    public function shouldStop(Server $server): bool
    {
        $impact = $server->stopImpact();

        if (! $this->config->promptStopServer) {
            if ($impact !== null && $this->config->stopServerByDefault) {
                note("Leaving {$server->label()} running — stopping it needs an explicit yes: {$impact}");

                return false;
            }

            return $this->config->stopServerByDefault;
        }

        if ($impact !== null) {
            warning($impact);
        }

        return confirm(
            label: "Stop {$server->label()}? Other projects may be using it.",
            default: $impact === null && $this->config->stopServerByDefault,
        );
    }
}
