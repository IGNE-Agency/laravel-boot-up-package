<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

/**
 * Why a connection attempt failed, in the only terms the boot acts on.
 *
 * Transient is the waiting path: a database container legitimately refuses
 * connections while it initializes, so the readiness timeout — not a
 * catalogue of error strings — decides when to give up. The other two are
 * settled verdicts that no amount of waiting improves, and under a server
 * that bakes its database into storage at creation both are fixable only by
 * recreating that storage, never by editing .env.
 */
enum DatabaseFailure: string
{
    case AccessDenied = 'access-denied';
    case UnknownDatabase = 'unknown-database';
    case Transient = 'transient';

    /**
     * Whether the verdict is settled — retrying a terminal failure only
     * burns the timeout before reporting the same thing.
     */
    public function isTerminal(): bool
    {
        return $this !== self::Transient;
    }

    /**
     * Whether recreating the server's database storage could plausibly fix
     * it: both terminal failures name something the storage baked in at
     * creation (the credentials, the database itself).
     */
    public function fixableByRecreatingStorage(): bool
    {
        return $this->isTerminal();
    }
}
