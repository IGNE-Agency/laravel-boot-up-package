<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Exceptions;

final class FileException extends BootUpException
{
    public static function writeFailed(string $path): self
    {
        return new self("Could not write [{$path}]; check the directory exists and is writable.");
    }

    public static function moveFailed(string $from, string $to): self
    {
        return new self("Could not move [{$from}] to [{$to}].");
    }
}
