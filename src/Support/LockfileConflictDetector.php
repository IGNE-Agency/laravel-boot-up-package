<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Support;

final class LockfileConflictDetector
{
    private const PATTERNS = [
        'lock file is not up to date',
        'hash does not match',
        'content-hash',
        'lock file out of date',
        'run `composer update`',
        'lockfile had changes',
        'frozen-lockfile',
        'your lockfile needs to be updated',
        'cannot install with "frozen-lockfile"',
    ];

    public function isLockfileConflict(string $errorMessage): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (stripos($errorMessage, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
