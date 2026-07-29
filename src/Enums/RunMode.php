<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

/**
 * Where a long-running worker's output lives: combined into the app:serve
 * terminal with a colored [name] prefix, its own terminal window, or
 * detached in the background with a log file.
 */
enum RunMode: string
{
    case Combined = 'combined';
    case Terminal = 'terminal';
    case Background = 'background';

    /**
     * Unknown strings mean background, preserving the historic loose
     * fall-through for published configs with unexpected values.
     */
    public static function fromConfig(string $value): self
    {
        return self::tryFrom($value) ?? self::Background;
    }
}
