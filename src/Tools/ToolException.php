<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools;

use Igne\LaravelBootUp\Support\BootUpException;

final class ToolException extends BootUpException
{
    public static function notInstalled(string $label): self
    {
        return new self("{$label} is not installed. Install it manually or enable boot-up.tools.auto_install.");
    }

    public static function unknownTool(string $id): self
    {
        return new self("No installer is known for tool '{$id}'. Register one under boot-up.tools.installers.");
    }

    public static function installFailed(string $label): self
    {
        return new self("Installing {$label} failed. Install it manually and re-run.");
    }
}
