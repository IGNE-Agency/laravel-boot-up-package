<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Boot\BootProcessProbe;
use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\ServerSelector;
use Throwable;

/**
 * Read-only view of everything boot-up is running: the active server and
 * each tracked background process. Deliberately mutates nothing — dead
 * entries are shown as dead and pruned by the next boot, not here.
 */
final class StatusCommand extends BootUpCommand
{
    protected $signature = 'app:status';

    protected $description = 'Show the active server and tracked background processes';

    protected function requiresUnix(): bool
    {
        return true;
    }

    public function handle(
        ActiveServerStore $store,
        ProcessLedger $ledger,
        ProcessReaper $reaper,
        ServerSelector $selector,
        BootProcessProbe $probe,
    ): int {
        $this->announce('Application status');

        $active = $store->current();
        $records = $ledger->all();

        if ($active === null && $records->isEmpty()) {
            return $this->done('Nothing is running.');
        }

        if ($active !== null) {
            $this->describeServer($active, $selector, $probe);
        }

        $records->each(function (ProcessRecord $record) use ($reaper): void {
            $state = $reaper->isAlive($record) ? 'running' : 'dead';

            terminal()->info("{$record->label} (pid {$record->pid}): {$state} — {$record->outputLocation()}");
        });

        return $this->done('Stop everything with: php artisan app:down');
    }

    private function describeServer(ActiveServerRecord $active, ServerSelector $selector, BootProcessProbe $probe): void
    {
        // The driver key may belong to a custom driver that no longer
        // exists in config — the record itself is still worth showing.
        try {
            $server = $selector->driver($active->key);
            $name = "{$server->label()} at {$server->url()}";
        } catch (Throwable) {
            $name = $active->key;
        }

        // One Terminal call per line: artisan output expectations can match
        // at most one assertion per write.
        terminal()->info("Server: {$name}");
        terminal()->info($active->startedByUs
            ? 'The server was started by php artisan dev.'
            : 'The server was already running before php artisan dev started.');
        terminal()->info($probe->isServing($active->servePid)
            ? "php artisan dev is running (pid {$active->servePid})."
            : "Its php artisan dev (pid {$active->servePid}) is no longer running.");
    }
}
