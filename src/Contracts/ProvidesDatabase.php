<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

/**
 * Capability: the server provisions the configured database itself
 * (e.g. Sail's containers create it from .env), so the boot must not
 * try to create one. Servers without this contract get their database
 * created host-side when it is missing.
 */
interface ProvidesDatabase
{
    /**
     * Whether the host can still reach that database directly. When false,
     * database checks and migrations run through the server's command
     * rewrites instead of host-side PDO.
     */
    public function databaseReachableFromHost(): bool;
}
