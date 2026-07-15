<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Deploy;

use Igne\LaravelBootstrap\Support\BootstrapException;

final class DeployException extends BootstrapException
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
