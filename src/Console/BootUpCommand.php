<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Exceptions\BootUpException;
use Igne\LaravelBootUp\Facades\Platform;
use Igne\LaravelBootUp\Serve\StepSequence;
use Illuminate\Console\Command;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Throwable;

/**
 * Shared command lifecycle. Every boot-up command runs through the same
 * template: an optional native-Windows guard, then the command body, wrapped
 * in one two-tier exception funnel that turns a known failure (a
 * BootUpException or a process failure) into a clean error line and anything
 * else into an "Unexpected error" — always with the correct non-zero exit
 * code. Intro/outro wording stays with each command; only the cross-cutting
 * guard and failure handling live here.
 *
 * Subclasses implement `perform(): int` and declare their service
 * dependencies as parameters — they are resolved from the container exactly
 * as a native `handle()` would be.
 */
abstract class BootUpCommand extends Command
{
    /**
     * Commands that manage OS processes (serve, deploy, status) refuse to run
     * on native Windows; pure file generators leave this false.
     */
    protected bool $requiresUnix = false;

    public function handle(): int
    {
        if ($this->requiresUnix && Platform::isWindows()) {
            terminal()->error("{$this->getName()} is not supported on native Windows. Run it inside WSL2.");

            return self::FAILURE;
        }

        try {
            return (int) $this->laravel->call([$this, 'perform']);
        } catch (BootUpException|ProcessFailedException|ProcessTimedOutException $exception) {
            return $this->reportFailure($exception->getMessage());
        } catch (Throwable $exception) {
            return $this->reportFailure('Unexpected error: '.$exception->getMessage());
        }
    }

    /**
     * Settle the failure: let the command clean up (e.g. mark a progress bar
     * failed), print the error, and append the command's recovery hint. The
     * hint fires on every failure because a mid-boot failure can leave
     * background processes running; it is a no-op for commands that do not
     * override failureHint().
     */
    private function reportFailure(string $message): int
    {
        $this->onFailure();
        terminal()->error($message);
        $this->failureHint();

        return self::FAILURE;
    }

    /**
     * Show what the command is about to do and ask to continue. Returns false
     * only when the user actively declines; auto-accept (the boot-up config
     * flag or the --yes flag) and non-interactive contexts skip the prompt.
     */
    protected function confirmPlan(StepSequence $plan, string $command, bool $autoAccept): bool
    {
        terminal()->section("What {$command} will do");
        terminal()->list($plan->summary());

        if ($autoAccept || (bool) $this->option('yes')) {
            return true;
        }

        return terminal()->confirm('Continue?', default: true);
    }

    /**
     * Hook for commands that started work needing to be settled on failure
     * (e.g. a progress bar). No-op by default.
     */
    protected function onFailure(): void {}

    /**
     * Hook for a recovery hint printed only after an unexpected error (e.g.
     * pointing at `app:down` when background processes may still run).
     */
    protected function failureHint(): void {}
}
