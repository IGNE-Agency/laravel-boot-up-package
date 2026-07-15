<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures;

use Closure;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Pattern-keyed Process fakes matched against the plain token string.
 * The framework's own pattern matching sees the shell-escaped command
 * line for array commands ('docker' 'info'), which makes patterns
 * unreadable — this matches against `docker info` instead.
 */
final class ProcessFaker
{
    /**
     * @param  array<string, mixed>  $handlers  pattern => result | sequence | closure
     */
    public static function fake(array $handlers = []): void
    {
        Process::fake(function (PendingProcess $process) use ($handlers): mixed {
            $command = self::commandString($process);

            foreach ($handlers as $pattern => $handler) {
                if ($pattern === '*' || Str::is($pattern, $command)) {
                    return $handler instanceof Closure ? $handler($process) : $handler;
                }
            }

            return Process::result();
        });
    }

    public static function assertRan(string $pattern): void
    {
        Process::assertRan(fn (PendingProcess $process): bool => Str::is($pattern, self::commandString($process)));
    }

    public static function assertDidntRun(string $pattern): void
    {
        Process::assertDidntRun(fn (PendingProcess $process): bool => Str::is($pattern, self::commandString($process)));
    }

    public static function assertRanTimes(string $pattern, int $times): void
    {
        Process::assertRanTimes(
            fn (PendingProcess $process): bool => Str::is($pattern, self::commandString($process)),
            $times,
        );
    }

    private static function commandString(PendingProcess $process): string
    {
        return \is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;
    }
}
