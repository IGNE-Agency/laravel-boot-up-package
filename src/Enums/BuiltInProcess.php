<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

/**
 * The processes boot-up has an opinion about when `php artisan dev` runs.
 *
 * Four of these are Laravel's own defaults and three are boot-up's; what
 * they share is that the name is load-bearing. It is the tab in the dev
 * terminal, the ledger label and log file of a detached run, and the slot an
 * application registers under to take a process over. Naming them once keeps
 * those uses from drifting apart — renaming the watcher, for instance, would
 * otherwise quietly strand the stale-hot-file cleanup.
 *
 * The case order is the registration order: Laravel's four defaults first,
 * then the three boot-up appends. That is the order the tabs appear in, so
 * anything reporting on the process set reads in the same order the user
 * will see it.
 */
enum BuiltInProcess: string
{
    case Server = 'server';
    case Queue = 'queue';
    case Logs = 'logs';
    case Vite = 'vite';
    case Horizon = 'horizon';
    case Reverb = 'reverb';
    case Scheduler = 'scheduler';

    /**
     * The artisan command that makes a long-running service pick up new code
     * after a deploy, or null when there is nothing to restart.
     *
     * Horizon does not answer to queue:restart — it supervises its own
     * workers and terminates gracefully instead. The scheduler is absent on
     * purpose: in production that is cron invoking schedule:run, not a
     * process a deploy can signal.
     */
    public function restartCommand(): ?string
    {
        return match ($this) {
            self::Queue => 'queue:restart',
            self::Horizon => 'horizon:terminate',
            self::Reverb => 'reverb:restart',
            self::Server, self::Logs, self::Vite, self::Scheduler => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(fn (self $process): string => $process->value, self::cases());
    }
}
