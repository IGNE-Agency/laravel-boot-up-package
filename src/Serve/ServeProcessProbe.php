<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Process\ProcessRunner;

/**
 * Answers whether a recorded pid is still a live app:serve process — a
 * dead pid or one recycled by an unrelated command both count as "not
 * serving".
 */
final class ServeProcessProbe
{
    public function __construct(private readonly ProcessRunner $runner) {}

    public function isServing(int $pid): bool
    {
        $command = trim($this->runner->runSilently(
            CommandLine::make(['ps', '-p', (string) $pid, '-o', 'command=']),
        )->output());

        return str_contains($command, 'app:serve');
    }
}
