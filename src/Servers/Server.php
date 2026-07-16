<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Tools\Tool;

/**
 * A development server driver. Identity is a string key so consuming
 * projects can register their own drivers via config('boot-up.server.drivers').
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

    /**
     * Whether the server provisions the configured database itself
     * (e.g. Sail's containers create it from .env), so the boot must
     * not try to create one.
     */
    public function providesDatabase(): bool;

    /**
     * Whether the host can reach the configured database directly. When
     * false, database checks and migrations run through the server's
     * command rewrites instead of host-side PDO.
     */
    public function databaseReachableFromHost(): bool;

    /**
     * A warning shown before stop() when stopping reaches beyond this
     * project (e.g. `herd stop` halts every Herd site on the machine).
     * Null means stopping is project-scoped. A non-null impact is never
     * acted on without an explicit confirmation.
     */
    public function stopImpact(): ?string;

    public function isRunning(): bool;

    /**
     * Bring the server up. Must be idempotent and may wait for readiness;
     * throws Support\BootUpException subclasses on failure.
     */
    public function start(ServeContext $context): void;

    /**
     * Stop the server. Must only stop — never install, never prompt.
     */
    public function stop(): void;

    public function url(): string;
}
