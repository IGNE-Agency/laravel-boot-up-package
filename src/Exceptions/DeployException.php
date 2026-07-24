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
}
