<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Tools;

use Igne\LaravelBootstrap\Support\BootstrapException;

final class ToolException extends BootstrapException
{
    public static function notInstalled(string $label): self
    {
        return new self("{$label} is not installed. Install it manually or enable bootstrap.tools.auto_install.");
    }

    public static function unknownTool(string $id): self
    {
        return new self("No installer is known for tool '{$id}'. Register one under bootstrap.tools.installers.");
    }

    public static function installFailed(string $label): self
    {
        return new self("Installing {$label} failed. Install it manually and re-run.");
    }
}
