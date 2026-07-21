<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console\Support;

/**
 * Resolves a command choice from an optional positional argument, falling
 * back to an interactive select when the argument is absent. A supplied
 * argument is lowercased and returned verbatim — callers validate it against
 * their own option keys so they keep ownership of the "unknown X" error and
 * any enum coercion.
 */
final class Selection
{
    /**
     * @param  array<string, string>  $options  option key => human label
     */
    public function resolve(mixed $argument, array $options, string $prompt, ?string $default = null): string
    {
        if (\is_string($argument) && $argument !== '') {
            return strtolower($argument);
        }

        return (string) terminal()->select($prompt, $options, $default);
    }
}
