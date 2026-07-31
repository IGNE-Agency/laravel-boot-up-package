<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

use Igne\LaravelBootUp\Concerns\ResolvesFromConfig;

/**
 * Where a long-running worker's output lives: combined into the app:serve
 * terminal with a colored [name] prefix, its own terminal window, or
 * detached in the background with a log file.
 */
enum RunMode: string
{
    use ResolvesFromConfig;

    case Combined = 'combined';
    case Terminal = 'terminal';
    case Background = 'background';

    public static function default(): self
    {
        return self::Combined;
    }
}
