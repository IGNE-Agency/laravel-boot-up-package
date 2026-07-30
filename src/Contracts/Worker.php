<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Enums\RunMode;

/**
 * A tracked long-running process a pipeline step starts through the
 * WorkerLauncher. The worker step IS the worker: it describes itself
 * instead of building a definition record for something else to carry.
 */
interface Worker
{
    /**
     * The ledger label — the stable identity `app:status`, `app:down` and
     * the already-running check agree on.
     */
    public function label(): string;

    /**
     * The display name for terminal lines.
     */
    public function name(): string;

    /**
     * The command to run, before server rewrites.
     */
    public function command(): CommandLine;

    /**
     * Where the worker's output lives.
     */
    public function runIn(): RunMode;

    /**
     * The short [prefix] this worker streams under in combined mode.
     */
    public function streamName(): string;
}
