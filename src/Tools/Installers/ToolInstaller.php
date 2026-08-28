<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools\Installers;

use Igne\LaravelBootUp\Contracts\InstallsTool;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Tools\ToolInspector;

/**
 * Base for installers of built-in tools: identity and detection delegate
 * to the Tool case and the inspector, leaving subclasses only the actual
 * install/update behavior.
 */
abstract class ToolInstaller implements InstallsTool
{
    public function __construct(protected readonly ToolInspector $inspector) {}

    abstract protected function tool(): Tool;

    public function id(): string
    {
        return $this->tool()->value;
    }

    public function label(): string
    {
        return $this->tool()->label();
    }

    public function updatesAutomatically(): bool
    {
        return $this->tool()->updatesAutomatically();
    }

    public function isInstalled(): bool
    {
        return $this->inspector->isInstalled($this->tool());
    }

    public function installedVersion(): ?string
    {
        return $this->inspector->installedVersion($this->tool());
    }
}
