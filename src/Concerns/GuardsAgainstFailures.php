<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

use Closure;
use Igne\LaravelBootUp\Exceptions\BootUpException;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Throwable;

/**
 * The shared two-tier exception funnel: a known failure (a BootUpException
 * or a process failure) becomes a clean error line, anything else an
 * "Unexpected error" — always with a non-zero exit code.
 */
trait GuardsAgainstFailures
{
    /**
     * @param  Closure(): int  $run
     */
    protected function guardAgainstFailures(Closure $run): int
    {
        try {
            return $run();
        } catch (BootUpException|ProcessFailedException|ProcessTimedOutException $exception) {
            return $this->reportFailure($exception->getMessage());
        } catch (Throwable $exception) {
            return $this->reportFailure("Unexpected error: {$exception->getMessage()}");
        }
    }

    /**
     * Settle the failure: let the command clean up (e.g. mark a progress bar
     * failed), print the error, and append the command's recovery hint. The
     * hint fires on every failure because a mid-boot failure can leave
     * background processes running.
     */
    private function reportFailure(string $message): int
    {
        $this->onFailure();
        terminal()->error($message);
        $this->failureHint();

        return self::FAILURE;
    }

    /**
     * Hook for commands that started work needing to be settled on failure
     * (e.g. a progress bar). No-op by default.
     */
    protected function onFailure(): void {}

    /**
     * Hook for a recovery hint printed after every failure (e.g. pointing
     * at `app:down` when background processes may still run). No-op by
     * default.
     */
    protected function failureHint(): void {}
}
