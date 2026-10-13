<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database;

use Igne\LaravelBootUp\Enums\DatabaseFailure;
use Igne\LaravelBootUp\Services\PatternDetector;

/**
 * Classifies why a database connection attempt failed. Only the terminal
 * verdicts are enumerated: rejected credentials and a database the server
 * does not have fail immediately — no amount of waiting conjures either —
 * while everything else counts as "not ready yet" until the readiness
 * timeout bounds it. Enumerating the transient shapes (refused, still
 * resolving, initializing, container not up yet) would be brittle; the
 * timeout covers them all.
 */
final class DatabaseFailureDetector
{
    private const array ACCESS_DENIED_PATTERNS = [
        'access denied for user',          // mysql / mariadb (1045)
        'password authentication failed',  // pgsql (28P01)
        'login failed for user',           // sqlsrv (18456)
    ];

    private const array UNKNOWN_DATABASE_PATTERNS = [
        'unknown database',             // mysql / mariadb (1049)
        'cannot open database',         // sqlsrv (4060)
        'unable to open database file', // sqlite — an unopenable file stays unopenable
    ];

    /**
     * Two messages that name the database inside themselves, where every
     * needle short enough to be literal matches the wrong errors too:
     * "does not exist" also covers missing tables and relations, and
     * "access denied for user" alone cannot tell 1044 from 1045.
     *
     * MySQL only reports 1049 to a user allowed to see that the database is
     * missing; to everyone else — Sail's `sail` user included — a database
     * the server never created is a 1044 against a grant that was never
     * made. Same cause, same fix, so it is classified by what it is about
     * rather than by the verb it opens with.
     */
    private const array UNKNOWN_DATABASE_EXPRESSIONS = [
        '/database "[^"]*" does not exist/i',   // pgsql (3D000)
        '/access denied for user .* to database/i', // mysql / mariadb (1044)
    ];

    /**
     * Unknown-database first: a 1044 says "access denied" before it says
     * which database, so the broader needle would otherwise claim it.
     */
    public function classify(string $output): DatabaseFailure
    {
        if (PatternDetector::matchesAny($output, self::UNKNOWN_DATABASE_PATTERNS)
            || PatternDetector::matchesAnyExpression($output, self::UNKNOWN_DATABASE_EXPRESSIONS)) {
            return DatabaseFailure::UnknownDatabase;
        }

        if (PatternDetector::matchesAny($output, self::ACCESS_DENIED_PATTERNS)) {
            return DatabaseFailure::AccessDenied;
        }

        return DatabaseFailure::Transient;
    }

    /**
     * Whether waiting longer is pointless — the one question the poller
     * asks after every attempt.
     */
    public function isTerminal(string $output): bool
    {
        return $this->classify($output)->isTerminal();
    }
}
