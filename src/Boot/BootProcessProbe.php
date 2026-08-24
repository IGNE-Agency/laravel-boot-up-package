<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Boot;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Process\ProcessRunner;

/**
 * Answers whether a recorded pid is still a live app:setup — a dead pid or
 * one recycled by an unrelated command both count as "not serving".
 *
 * app:setup is what writes the active-server record and what a second
 * instance has to be kept out of. `dev` never writes one: it streams a
 * project that is already set up, and two of those clash on ports, visibly,
 * the way plain `php artisan dev` does.
 */
final class BootProcessProbe
{
    public function __construct(private readonly ProcessRunner $runner) {}

    public function isServing(int $pid): bool
    {
        $command = trim($this->runner->runSilently(
            CommandLine::make(['ps', '-p', (string) $pid, '-o', 'command=']),
        )->output());

        return str_contains($command, 'app:setup');
    }
}
