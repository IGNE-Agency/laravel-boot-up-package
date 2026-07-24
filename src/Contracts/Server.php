<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

use Igne\LaravelBootUp\Data\ServeContext;

/**
 * A development server driver. Identity is a string key so consuming
 * projects can register their own drivers via config('boot-up.server.drivers').
 *
 * This is the core every driver has. Optional capabilities are their own
 * contracts a driver adds only when they apply: ProvidesDatabase,
 * RequiresTools, RewritesCommands and WarnsBeforeStop.
 *
 * Constructors must be side-effect free: installation happens in the Tools
 * step (via RequiresTools), never in the driver, and never on teardown.
 */
interface Server
{
    public function key(): string;

    public function label(): string;

    public function isRunning(): bool;

    /**
     * Bring the server up. Must be idempotent and may wait for readiness;
     * throws Exceptions\BootUpException subclasses on failure.
     */
    public function start(ServeContext $context): void;

    /**
     * Stop the server. Must only stop — never install, never prompt.
     */
    public function stop(): void;

    public function url(): string;
}
