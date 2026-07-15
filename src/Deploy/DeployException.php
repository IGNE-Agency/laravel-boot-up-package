<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy;

use Igne\LaravelBootUp\Support\BootUpException;

final class DeployException extends BootUpException
{
    public static function commandFailed(ProjectCommand $command, string $reason): self
    {
        return new self("Project command '{$command->command}' failed; aborting. {$reason}");
    }

    public static function composerFailed(string $reason): self
    {
        return new self("Composer failed. {$reason}");
    }
}
