<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

/**
 * Capability: the server keeps its database state in storage it can destroy
 * and recreate — Sail's database volume, where the credentials are baked in
 * at first creation and .env changes never reach them again.
 *
 * Resetting destroys data: callers must get an explicit confirmation first,
 * and must never let an auto-accept flag stand in for one.
 */
interface ResetsDatabaseStorage
{
    /**
     * Whether the storage already existed before this boot started. Only
     * then can rejected credentials mean "stale storage" rather than a
     * mistake this boot made itself.
     */
    public function databaseStorageExistedBeforeBoot(): bool;

    /**
     * Whether the storage can actually be identified and destroyed right
     * now — false stands the recovery offer down entirely.
     */
    public function databaseStorageResettable(): bool;

    /**
     * What would be destroyed, named concretely enough to confirm against:
     * "Docker volume [myapp_sail-mysql]".
     */
    public function databaseStorageLabel(): string;

    /**
     * Destroy and recreate the storage so it re-initializes from the current
     * .env. Destroys data; the caller has already confirmed.
     */
    public function resetDatabaseStorage(): void;

    /**
     * The manual steps for a caller who declined, in order.
     *
     * @return list<string>
     */
    public function databaseStorageResetInstructions(): array;
}
