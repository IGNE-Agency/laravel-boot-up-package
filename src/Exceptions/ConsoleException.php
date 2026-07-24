<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Exceptions;

final class ConsoleException extends BootUpException
{
    /**
     * @param  list<string>  $valid
     */
    public static function unknownChoice(string $name, string $value, array $valid): self
    {
        $available = implode(', ', $valid);

        return new self("Unknown {$name} [{$value}]. Available: {$available}");
    }
}
