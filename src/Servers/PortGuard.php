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
use Igne\LaravelBootUp\Environment\EnvRestorePoint;
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
        private readonly EnvRestorePoint $envRestore,
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
        // Two kinds of move. A forward to your machine is true of the machine,
        // not of this server — something else owns 3306 whoever serves the
        // project — so it is kept. The address the application answers on
        // describes this server, and the teardown puts it back.
        $keep = [];
        $forThisServer = [];
        $lines = [];
        $assigned = [];

        foreach ($conflicts as $conflict) {
            $port = $conflict->port;
            $free = $this->firstFree($port->searchFrom(), $assigned);

            // Nowhere to move to is the same dead end as not being movable.
            if ($free === null) {
                throw ServerException::portsUnavailable($server->label(), $conflicts);
            }

            $assigned[] = $free;
            $lines[] = "{$conflict->held()} → {$port->envKey}={$free}";

            if ($port->urlKey === null) {
                $keep[(string) $port->envKey] = (string) $free;

                continue;
            }

            // The port only counts as moved once everything advertising it
            // agrees, so the URL moves with it or not at all.
            $forThisServer[(string) $port->envKey] = (string) $free;
            $forThisServer[$port->urlKey] = $url = $this->urlOnPort($port->urlKey, $free);
            $lines[] = "{$port->urlKey}={$url}";
        }

        terminal()->summary(
            "{$server->label()} needs host ports that are already in use",
            $lines,
            'Only where the port is yours to move: a forward to your machine, or the address the application answers on.',
        );

        if ($autoAccept) {
            terminal()->warning('Moving them to free ports in your .env.');
        } elseif (! terminal()->confirm('Move them and continue?', default: true)) {
            throw ServerException::portsUnavailable($server->label(), $conflicts);
        }

        $this->envFile->setMany($keep);
        $this->envRestore->around(fn () => $this->envFile->setMany($forThisServer));

        terminal()->success('Ports updated in .env.');
    }

    /**
     * The URL in $key with its port replaced. A URL that names no host at all
     * is replaced outright — there is nothing in it worth preserving.
     */
    private function urlOnPort(string $key, int $port): string
    {
        $parts = parse_url($this->envFile->valueOr($key, ''));

        if ($parts === false || ! isset($parts['host'])) {
            return "http://localhost:{$port}";
        }

        $scheme = $parts['scheme'] ?? 'http';
        $path = rtrim($parts['path'] ?? '', '/');

        return "{$scheme}://{$parts['host']}:{$port}{$path}";
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
