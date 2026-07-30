<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

use Igne\LaravelBootUp\Exceptions\ConfigException;

/**
 * What app:serve does about frontend assets: keep a watcher running,
 * build them once, or leave them alone entirely.
 */
enum AssetMode: string
{
    case Watch = 'watch';
    case Build = 'build';
    case Skip = 'skip';

    /**
     * Null and '' mean "use the default"; anything else must be a case.
     */
    public static function fromConfig(mixed $value, string $key, self $default): self
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return self::tryFrom((string) $value) ?? throw ConfigException::invalidEnumValue($key, (string) $value, self::class);
    }
}
