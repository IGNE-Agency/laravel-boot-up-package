<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools;

use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;

/**
 * Answers "is this tool on the machine, and which version?" for the
 * built-in Tool enum cases.
 */
final class ToolInspector
{
    public function __construct(
        private readonly ProcessRunner $processes,
    ) {}

    public function isInstalled(Tool $tool): bool
    {
        return $this->processes->isCommandAvailable($tool->binary());
    }

    public function installedVersion(Tool $tool): ?string
    {
        $result = $this->processes->runSilently(ShellCommand::make($tool->versionCommand()));

        return $result->successful() ? $tool->parseVersion($result->output()) : null;
    }
}
