<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRecord;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\ServerSelector;
use Igne\LaravelBootUp\Servers\StopServerPrompt;

/**
 * The single teardown path, shared by app:down and the Ctrl-C trap on
 * app:serve. Only ever considers the server app:serve itself started, and
 * clears all state so a second invocation is a friendly no-op.
 */
final class ShutdownRunner
{
    private bool $hasRun = false;

    public function __construct(
        private readonly ProcessLedger $ledger,
        private readonly ProcessReaper $reaper,
        private readonly ActiveServerStore $store,
        private readonly ServerSelector $selector,
        private readonly StopServerPrompt $prompt,
    ) {}

    public function run(): void
    {
        if ($this->hasRun) {
            return;
        }

        $this->hasRun = true;

        $active = $this->store->current();

        if ($active === null && $this->ledger->isEmpty()) {
            terminal()->info('Nothing to shut down.');

            return;
        }

        $this->ledger->all()->each(function (ProcessRecord $record): void {
            terminal()->info("Stopping {$record->label} (pid {$record->pid})...");
        });

        // reap() already forgets confirmed-dead entries; only remove the
        // ledger file itself when nothing survived the signals.
        if ($this->reaper->reapAll()) {
            $this->ledger->clear();
        }

        if ($active !== null) {
            $this->stopServer($active->key, $active->startedByUs);
        }

        $this->store->clear();

        terminal()->success('Shutdown complete.');
    }

    private function stopServer(string $key, bool $startedByUs): void
    {
        if (! $startedByUs) {
            terminal()->note("Leaving {$key} running — it was already running before app:serve started.");

            return;
        }

        $server = $this->selector->driver($key);

        if (! $server->isRunning()) {
            return;
        }

        if ($this->prompt->shouldStop($server)) {
            $server->stop();
            terminal()->success("{$server->label()} stopped.");

            return;
        }

        terminal()->note("Keeping {$server->label()} running.");
    }
}
