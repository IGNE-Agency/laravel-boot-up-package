<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

/**
 * The shared open/close vocabulary for command runs: every command opens
 * with announce() and every exit path returns done(), skip() or FAILURE.
 * Deliberately explicit calls instead of an automatic wrapper — intros may
 * come after pre-flight checks and commands can have several distinct
 * endings.
 */
trait AnnouncesRun
{
    protected function announce(string $intro): void
    {
        terminal()->intro($intro);
    }

    /**
     * Successful completion: outro and SUCCESS in one expression.
     */
    protected function done(string $outro): int
    {
        terminal()->outro($outro);

        return self::SUCCESS;
    }

    /**
     * Graceful non-run (declined plan, nothing to do): note and SUCCESS.
     */
    protected function skip(string $note): int
    {
        terminal()->note($note);

        return self::SUCCESS;
    }
}
