<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Exceptions;

use Igne\LaravelBootUp\Exceptions\BootUpException;
use Igne\LaravelBootUp\Data\ProjectCommand;
use Igne\LaravelBootUp\Deploy\Composer;

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
