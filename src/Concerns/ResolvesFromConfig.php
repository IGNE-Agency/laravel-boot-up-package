<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

use Igne\LaravelBootUp\Exceptions\ConfigException;

/**
 * For backed enums read from the config file: null and '' mean "use the
 * default"; anything else must be a case — a typo'd value silently falling
 * back is worse than a boot-time error naming the key.
 */
trait ResolvesFromConfig
{
    abstract public static function default(): self;

    public static function fromConfig(mixed $value, string $key): self
    {
        return self::fromConfigOrNull($value, $key) ?? self::default();
    }

    /**
     * For keys where "unset" means something richer than the enum default
     * (detection, prompting) and the caller handles null itself.
     */
    public static function fromConfigOrNull(mixed $value, string $key): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom((string) $value) ?? throw ConfigException::invalidEnumValue($key, (string) $value, self::class);
    }
}
