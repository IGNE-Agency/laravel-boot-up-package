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
 */
enum BuiltInProcess: string
{
    case Server = 'server';
    case Queue = 'queue';
    case Horizon = 'horizon';
    case Reverb = 'reverb';
    case Scheduler = 'scheduler';
    case Vite = 'vite';
    case Logs = 'logs';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(fn (self $process): string => $process->value, self::cases());
    }
}
