<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

use Igne\LaravelBootUp\Config\DevServerConfig;
use Igne\LaravelBootUp\Config\SetupConfig;
use Igne\LaravelBootUp\Contracts\ReservesPorts;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\PortConflict;
use Igne\LaravelBootUp\Data\ReservedPort;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Exceptions\ServerException;
use Igne\LaravelBootUp\Services\PortProbe;

/**
 * Proves the ports a server needs are free before it is asked to start.
 *
 * Docker reports a clash only after it has created the network, the volumes
 * and the containers, and reports it as a raw daemon error naming one port at
 * a time. Probing first turns that into a sentence per port, with nothing left
 * behind to clean up -- and where the port is a host-side forward the
 * application does not read, into an offer to move it.
 */
final class PortGuard
{
    public function __construct(
        private readonly PortProbe $probe,
        private readonly EnvFile $envFile,
        private readonly DevServerConfig $serverConfig,
        private readonly SetupConfig $setupConfig,
    ) {}

    /**
     * Throws when a needed port is taken and cannot be moved. Callers must
     * only reach this for a server that is not already running: a running one
     * holds its own ports, and would be reported as clashing with itself.
     */
    public function guard(BootContext $context): void
    {
        $server = $context->server;

        if (! $server instanceof ReservesPorts || ! $this->serverConfig->checkPorts) {
            return;
        }

        $conflicts = $this->conflicts($server->reservedPorts());

        if ($conflicts === []) {
            return;
        }

        // Moving a subset leaves the boot just as broken, so a single
        // unmovable port settles it for all of them.
        foreach ($conflicts as $conflict) {
            if (! $conflict->port->isRemappable()) {
                throw ServerException::portsUnavailable($server->label(), $conflicts);
            }
        }

        $this->offerToMove($server, $conflicts, $this->autoAccepts($context));
    }

    /**
     * @param  list<ReservedPort>  $ports
     * @return list<PortConflict>
     */
    private function conflicts(array $ports): array
    {
        $conflicts = [];

        foreach ($ports as $port) {
            if ($this->probe->isAvailable($port->port)) {
                continue;
            }

            $conflicts[] = new PortConflict($port, $this->probe->holderOf($port->port));
        }

        return $conflicts;
    }

    /**
     * @param  list<PortConflict>  $conflicts
     */
    private function offerToMove(Server $server, array $conflicts, bool $autoAccept): void
    {
        $moves = [];
        $lines = [];
        $assigned = [];

        foreach ($conflicts as $conflict) {
            $free = $this->firstFree($conflict->port->port + 1, $assigned);

            // Nowhere to move to is the same dead end as not being movable.
            if ($free === null) {
                throw ServerException::portsUnavailable($server->label(), $conflicts);
            }

            $assigned[] = $free;
            $moves[(string) $conflict->port->envKey] = (string) $free;
            $lines[] = "{$conflict->held()} → {$conflict->port->envKey}={$free}";
        }

        terminal()->summary(
            "{$server->label()} needs host ports that are already in use",
            $lines,
            'These only forward a container port to your machine — the application reaches the service over the container network either way.',
        );

        if ($autoAccept) {
            terminal()->warning('Moving them to free ports in your .env.');
        } elseif (! terminal()->confirm('Move them and continue?', default: true)) {
            throw ServerException::portsUnavailable($server->label(), $conflicts);
        }

        $this->envFile->setMany($moves);

        terminal()->success('Port forwards updated in .env.');
    }

    /**
     * The first free port at or after $from that this run has not already
     * handed to another conflict — two mappings moved in one pass must not be
     * moved onto each other.
     *
     * @param  list<int>  $assigned
     */
    private function firstFree(int $from, array $assigned): ?int
    {
        $candidate = $from;

        while (($free = $this->probe->nextAvailable($candidate)) !== null) {
            if (! \in_array($free, $assigned, true)) {
                return $free;
            }

            $candidate = $free + 1;
        }

        return null;
    }

    private function autoAccepts(BootContext $context): bool
    {
        return $this->setupConfig->autoAccept || $context->options->autoAccept;
    }
}
