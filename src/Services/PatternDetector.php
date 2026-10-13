<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Services;

use Illuminate\Support\Str;

/**
 * Case-insensitive needle matching for classifying tool output — the one
 * check every "does this error mean X" detector shares.
 *
 * Two Str::contains() caveats the pattern lists must respect: an empty
 * needle never matches (stripos would have matched it), and case folds via
 * mb_strtolower — both irrelevant while every pattern is a non-empty ASCII
 * literal, which is what the detectors' consts hold.
 */
final class PatternDetector
{
    /**
     * @param  list<string>  $patterns
     */
    public static function matchesAny(string $haystack, array $patterns): bool
    {
        return Str::contains($haystack, $patterns, ignoreCase: true);
    }

    /**
     * The same question for messages a literal needle cannot pin down —
     * where the distinguishing part sits around an interpolated value, and
     * a substring loose enough to span it would match the wrong errors too.
     *
     * @param  list<string>  $expressions  full regular expressions, delimiters included
     */
    public static function matchesAnyExpression(string $haystack, array $expressions): bool
    {
        foreach ($expressions as $expression) {
            if (preg_match($expression, $haystack) === 1) {
                return true;
            }
        }

        return false;
    }
}
