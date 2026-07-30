<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

use Igne\LaravelBootUp\Config\ShutdownConfig;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Contracts\WarnsBeforeStop;

/**
 * Asks (config-gated) whether shutdown should stop a server that
 * app:serve itself started. A server whose stop reaches beyond this
 * project (WarnsBeforeStop) is never stopped without an explicit yes.
 */
final class StopServerPrompt
{
    public function __construct(private readonly ShutdownConfig $config) {}

    public function shouldStop(Server $server, bool $startedByUs = true): bool
    {
        $impact = $server instanceof WarnsBeforeStop ? $server->stopImpact() : null;

        // A server boot-up did not start is never stopped by default; it can
        // only be stopped with an explicit yes.
        $stopByDefault = $startedByUs && $this->config->stopServerByDefault;

        if (! $this->config->promptStopServer) {
            if ($impact !== null && $stopByDefault) {
                terminal()->note("Leaving {$server->label()} running — stopping it needs an explicit yes: {$impact}");

                return false;
            }

            return $stopByDefault;
        }

        if ($impact !== null) {
            terminal()->warning($impact);
        }

        return terminal()->confirm(
            label: "Stop {$server->label()}? Other projects may be using it.",
            default: $impact === null && $stopByDefault,
        );
    }

    /**
     * Residual-state cleanup is project-scoped (WarnsBeforeStop does not
     * apply), but never runs Docker commands silently unless the user opted
     * into unattended stops.
     */
    public function shouldCleanUp(Server $server): bool
    {
        if (! $this->config->promptStopServer) {
            return $this->config->stopServerByDefault;
        }

        return terminal()->confirm("Clean up {$server->label()}'s leftover resources?");
    }
}
