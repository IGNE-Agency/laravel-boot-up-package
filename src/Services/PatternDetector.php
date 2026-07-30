<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Services;

/**
 * Case-insensitive needle matching for classifying tool output — the one
 * loop every "does this error mean X" detector shares.
 */
final class PatternDetector
{
    /**
     * @param  list<string>  $patterns
     */
    public static function matchesAny(string $haystack, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (stripos($haystack, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
