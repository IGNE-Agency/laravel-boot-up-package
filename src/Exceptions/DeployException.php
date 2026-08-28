<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Exceptions;

use Igne\LaravelBootUp\Data\DeployTask;

final class DeployException extends BootUpException
{
    public static function commandFailed(DeployTask $command, string $reason): self
    {
        return new self("Project command '{$command->command}' failed; aborting. {$reason}");
    }

    public static function composerFailed(string $reason): self
    {
        return new self("Composer failed. {$reason}");
    }

    public static function emptyCommand(): self
    {
        return new self('Project command cannot be empty.');
    }

    public static function shellMetacharacters(string $command): self
    {
        return new self(
            "Project command '{$command}' contains shell metacharacters; commands run as plain argument lists and cannot chain, pipe or redirect."
        );
    }

    public static function blockedWord(string $command, string $word): self
    {
        return new self("Project command '{$command}' contains the blocked word '{$word}'.");
    }
}
