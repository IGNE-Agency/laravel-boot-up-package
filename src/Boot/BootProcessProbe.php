<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Boot;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Process\ProcessRunner;

/**
 * Answers whether a recorded pid is still a live app:up — a dead pid or
 * one recycled by an unrelated command both count as "not serving".
 *
 * app:up is what writes the active-server record and what a second
 * instance has to be kept out of. `dev` never writes one: it streams a
 * project that is already set up, and two of those clash on ports, visibly,
 * the way plain `php artisan dev` does.
 */
final class BootProcessProbe
{
    /**
     * What a live boot looks like in the process table. Matched as a whole
     * word: `app:up` is short enough to appear inside a project's own
     * app:upgrade, and a false positive here refuses a legitimate boot.
     */
    private const string RUNNING_BOOT = '/\bapp:up\b/';

    public function __construct(private readonly ProcessRunner $runner) {}

    public function isServing(int $pid): bool
    {
        $command = trim($this->runner->runSilently(
            CommandLine::make(['ps', '-p', (string) $pid, '-o', 'command=']),
        )->output());

        return preg_match(self::RUNNING_BOOT, $command) === 1;
    }
}
