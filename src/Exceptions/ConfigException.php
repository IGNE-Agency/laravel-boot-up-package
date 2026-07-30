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
}
