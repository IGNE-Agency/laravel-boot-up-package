<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Process\ProcessRunner;

/**
 * Answers whether a recorded pid is still a live boot process — a dead pid
 * or one recycled by an unrelated command both count as "not serving".
 *
 * Both names are matched: `dev` is the command, `app:serve` its deprecated
 * alias, and a boot started under the old name still owns the project.
 */
final class ServeProcessProbe
{
    public function __construct(private readonly ProcessRunner $runner) {}

    public function isServing(int $pid): bool
    {
        $command = trim($this->runner->runSilently(
            CommandLine::make(['ps', '-p', (string) $pid, '-o', 'command=']),
        )->output());

        return str_contains($command, 'artisan dev') || str_contains($command, 'app:serve');
    }
}
