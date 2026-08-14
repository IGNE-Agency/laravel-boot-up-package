<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Exceptions;

final class BootCommandException extends BootUpException
{
    public static function reservedName(string $name): self
    {
        return new self("The name [{$name}] is reserved for the development server; register the boot command under another name.");
    }
}
