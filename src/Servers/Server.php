<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Servers;

use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Tools\Tool;

/**
 * A development server driver. Identity is a string key so consuming
 * projects can register their own drivers via config('bootstrap.server.drivers').
 *
 * Constructors must be side-effect free: installation happens in the Tools
 * step (via requiredTools()), never in the driver, and never on teardown.
 */
interface Server
{
    public function key(): string;

    public function label(): string;

    /**
     * Tools that must be present on the host before start() runs.
     *
     * @return list<Tool>
     */
    public function requiredTools(): array;

    public function commandRewrites(): CommandRewrites;

    public function isRunning(): bool;

    /**
     * Bring the server up. Must be idempotent and may wait for readiness;
     * throws Support\BootstrapException subclasses on failure.
     */
    public function start(ServeContext $context): void;

    /**
     * Stop the server. Must only stop — never install, never prompt.
     */
    public function stop(): void;

    public function url(): string;
}
