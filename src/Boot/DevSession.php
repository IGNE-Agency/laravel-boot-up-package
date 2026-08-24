<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Boot;

/**
 * Who the dev session belongs to.
 *
 * `php artisan dev` on its own is a passthrough: upstream ends in pcntl_exec,
 * which replaces the PHP process with the terminal UI outright, and that is
 * what makes its keys and its quit behaviour identical to Laravel's own
 * command. A session app:setup started cannot end there — the boot behind it
 * owns a server that has to be stopped again when the terminal quits, and an
 * exec'd process leaves no PHP behind to stop it. So app:setup claims the
 * session first, and `dev` hands the terminal to a child process instead,
 * returning control when it exits.
 *
 * Shared state because the claim crosses a command boundary: app:setup sets
 * it, `dev` reads it, both inside the same artisan process.
 */
final class DevSession
{
    private bool $claimed = false;

    /**
     * Take ownership of the dev session about to start: it must hand control
     * back to this command when the terminal UI quits.
     */
    public function claim(): void
    {
        $this->claimed = true;
    }

    public function isClaimed(): bool
    {
        return $this->claimed;
    }
}
