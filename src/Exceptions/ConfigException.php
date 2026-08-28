<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Exceptions;

use BackedEnum;

final class ConfigException extends BootUpException
{
    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public static function invalidEnumValue(string $key, string $value, string $enum): self
    {
        $legal = implode(', ', array_column($enum::cases(), 'value'));

        return new self("Config [{$key}] has an unknown value [{$value}]; legal values are: {$legal}.");
    }

    public static function missingClass(string $key, string $class): self
    {
        return new self("Config [{$key}] names the class [{$class}], which does not exist.");
    }

    public static function wrongContract(string $key, string $class, string $contract): self
    {
        return new self("Config [{$key}] names the class [{$class}], which does not implement [{$contract}].");
    }

    public static function outOfRange(string $key, int|string $value, string $expected): self
    {
        return new self("Config [{$key}] is [{$value}]; expected {$expected}.");
    }

    /**
     * A value of the wrong shape entirely — $actualType is a type name
     * (get_debug_type), rendered without brackets.
     */
    public static function invalidType(string $key, string $actualType, string $expected = 'a string'): self
    {
        return new self("Config [{$key}] is {$actualType}; expected {$expected}.");
    }

    /**
     * A value of the right type that fails validation — rendered bracketed,
     * like every other factory here, so callers never pre-format it.
     */
    public static function invalidValue(string $key, string $value, string $expected): self
    {
        return new self("Config [{$key}] is [{$value}]; expected {$expected}.");
    }
}
