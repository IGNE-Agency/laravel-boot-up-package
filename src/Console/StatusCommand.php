<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRecord;
use Igne\LaravelBootUp\Serve\ServeProcessProbe;
use Igne\LaravelBootUp\Servers\ActiveServer;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\ServerSelector;
use Igne\LaravelBootUp\Support\Platform;
use Illuminate\Console\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

use Throwable;

/**
 * Read-only view of everything boot-up is running: the active server and
 * each tracked background process. Deliberately mutates nothing — dead
 * entries are shown as dead and pruned by the next app:serve, not here.
 */
final class StatusCommand extends Command
{
    protected $signature = 'app:status';

    protected $description = 'Show the active server and tracked background processes';

    public function handle(
        ActiveServerStore $store,
        ProcessLedger $ledger,
        ProcessReaper $reaper,
        ServerSelector $selector,
        ServeProcessProbe $probe,
        Platform $platform,
    ): int {
        if ($platform->isWindows()) {
            error('app:status is not supported on native Windows. Run it inside WSL2.');

            return self::FAILURE;
        }

        $active = $store->current();
        $records = $ledger->all();

        if ($active === null && $records->isEmpty()) {
            info('Nothing is running.');

            return self::SUCCESS;
        }

        if ($active !== null) {
            $this->describeServer($active, $selector, $probe);
        }

        $records->each(function (ProcessRecord $record) use ($reaper): void {
            $state = $reaper->isAlive($record) ? 'running' : 'dead';

            note("{$record->label} (pid {$record->pid}): {$state} — logs: storage/logs/boot-up/{$record->label}.log");
        });

        note('Stop everything with: php artisan app:down');

        return self::SUCCESS;
    }

    private function describeServer(ActiveServer $active, ServerSelector $selector, ServeProcessProbe $probe): void
    {
        // The driver key may belong to a custom driver that no longer
        // exists in config — the record itself is still worth showing.
        try {
            $server = $selector->driver($active->key);
            $name = "{$server->label()} at {$server->url()}";
        } catch (Throwable) {
            $name = $active->key;
        }

        // One note() per line: artisan output expectations can match at
        // most one assertion per write.
        note("Server: {$name}");
        note($active->startedByUs
            ? 'The server was started by app:serve.'
            : 'The server was already running before app:serve.');
        note($probe->isServing($active->servePid)
            ? "app:serve is running (pid {$active->servePid})."
            : "Its app:serve (pid {$active->servePid}) is no longer running.");
    }
}
