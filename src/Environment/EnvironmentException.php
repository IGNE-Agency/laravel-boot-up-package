<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Environment;

use Igne\LaravelBootUp\Support\BootUpException;

final class EnvironmentException extends BootUpException
{
    public static function missingExampleFile(): self
    {
        return new self('No .env or .env.example file found. Create one to boot the application.');
    }

    public static function missingEnvFile(): self
    {
        return new self('The .env file does not exist. Create it before writing environment values.');
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function unsupportedEnvironment(string $env, array $allowed): self
    {
        $environments = implode(', ', $allowed);

        return new self("This command only runs when APP_ENV is one of [{$environments}] (boot-up.environments); APP_ENV is [{$env}].");
    }

    public static function remoteHost(): self
    {
        return new self('This command refuses to run on a remote host (SSH session detected).');
    }
}
