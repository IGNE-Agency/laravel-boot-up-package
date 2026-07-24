<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Services;

use Closure;

final class Poller
{
    /**
     * Poll the condition until it returns true or the timeout elapses.
     */
    public function until(Closure $condition, int $timeoutSeconds = 60, int $intervalMs = 500): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }

            usleep($intervalMs * 1000);
        }

        return (bool) $condition();
    }
}
