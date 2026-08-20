<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\CommandLine;

/**
 * Capability: the server runs as one of the dev processes, streamed
 * alongside the queue worker and the asset watcher. Servers without this
 * contract are external to the run — Herd serves through its own nginx —
 * and the dev command carries no [server] process for them.
 */
interface ProvidesDevProcess
{
    /**
     * The command to run as the [server] process, or null when this run
     * has already started the server some other way.
     */
    public function devProcess(BootContext $context): ?CommandLine;
}
