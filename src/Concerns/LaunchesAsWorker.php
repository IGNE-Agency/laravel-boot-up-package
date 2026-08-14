<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

use Closure;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Enums\StreamColor;
use Igne\LaravelBootUp\Serve\WorkerLauncher;

/**
 * The pipeline plumbing for a step that IS a worker (Contracts\Worker):
 * gate, launch, pass the context on. A trait rather than an abstract base
 * so worker steps stay final.
 */
trait LaunchesAsWorker
{
    public function handle(ServeContext $context, Closure $next): mixed
    {
        if ($this->launcher()->allows($this) && $this->shouldRun($context)) {
            $this->launcher()->launch($this, $context);
        }

        return $next($context);
    }

    /**
     * The config/context gate. Silent by default — detect-and-skip workers
     * never announce their absence. A worker that wants to explain itself
     * prints its own note and returns false.
     */
    protected function shouldRun(ServeContext $context): bool
    {
        return true;
    }

    /**
     * Workers stream under their ledger label unless they say otherwise.
     */
    public function streamName(): string
    {
        return $this->label();
    }

    /**
     * Built-in workers take whatever palette color the stream assigns.
     */
    public function streamColor(): ?StreamColor
    {
        return null;
    }

    /**
     * PHP cannot require a promoted property from a trait, so the launcher
     * arrives through an accessor.
     */
    abstract protected function launcher(): WorkerLauncher;
}
