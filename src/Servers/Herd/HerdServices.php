<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Herd;

use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;

/**
 * Honest Herd health: at least one of its core services is running,
 * rather than the old "a process named herd exists" check.
 */
final class HerdServices
{
    private const array SERVICES = ['nginx', 'php-fpm'];

    public function __construct(private readonly ProcessRunner $runner) {}

    public function isHealthy(): bool
    {
        foreach (self::SERVICES as $service) {
            if ($this->runner->runSilently(ShellCommand::make(['pgrep', '-x', $service]))->successful()) {
                return true;
            }
        }

        return false;
    }
}
