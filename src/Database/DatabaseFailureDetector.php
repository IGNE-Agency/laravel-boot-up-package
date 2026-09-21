<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database;

use Igne\LaravelBootUp\Services\PatternDetector;

/**
 * Classifies why a database connection attempt failed. Only one distinction
 * matters to the boot: rejected credentials fail immediately — no amount of
 * waiting fixes a wrong password — while everything else counts as "not
 * ready yet" until the readiness timeout bounds it. Enumerating the
 * transient shapes (refused, still resolving, initializing) would be
 * brittle; the timeout covers them all.
 */
final class DatabaseFailureDetector
{
    private const array ACCESS_DENIED_PATTERNS = [
        'access denied for user',          // mysql / mariadb (1045)
        'password authentication failed',  // pgsql (28P01)
        'login failed for user',           // sqlsrv (18456)
    ];

    public function isAccessDenied(string $output): bool
    {
        return PatternDetector::matchesAny($output, self::ACCESS_DENIED_PATTERNS);
    }
}
