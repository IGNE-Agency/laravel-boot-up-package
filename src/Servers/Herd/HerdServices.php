<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Herd;

use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;

/**
 * Herd is healthy when at least one of its OWN core services runs. The
 * patterns require the Herd installation path in the command line: a bare
 * service name would match any nginx/php-fpm on the host and corrupt the
 * started-by-us bookkeeping in both directions.
 */
final class HerdServices
{
    private const array SERVICE_PATTERNS = [
        'Herd[^ ]*nginx',
        'Herd[^ ]*php-fpm',
    ];

    public function __construct(private readonly ProcessRunner $runner) {}

    public function isHealthy(): bool
    {
        foreach (self::SERVICE_PATTERNS as $pattern) {
            if ($this->runner->runSilently(ShellCommand::make(['pgrep', '-f', $pattern]))->successful()) {
                return true;
            }
        }

        return false;
    }
}
