<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

use Igne\LaravelBootUp\Exceptions\ConfigException;

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
     * Null and '' mean "use the default"; anything else must be a case —
     * a typo'd mode silently running a worker in the wrong place is worse
     * than a boot-time error naming the key.
     */
    public static function fromConfig(mixed $value, string $key, self $default): self
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return self::tryFrom((string) $value) ?? throw ConfigException::invalidEnumValue($key, (string) $value, self::class);
    }
}
