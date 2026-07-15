<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Serve;

use Igne\LaravelBootstrap\Process\ProcessLedger;
use Igne\LaravelBootstrap\Process\ProcessReaper;
use Igne\LaravelBootstrap\Process\ProcessRecord;
use Igne\LaravelBootstrap\Servers\ActiveServerStore;
use Igne\LaravelBootstrap\Servers\ServerSelector;
use Igne\LaravelBootstrap\Servers\StopServerPrompt;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

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
            info('Nothing to shut down.');

            return;
        }

        $this->ledger->all()->each(function (ProcessRecord $record): void {
            note("Stopping {$record->label} (pid {$record->pid})...");
        });

        $this->reaper->reapAll();
        $this->ledger->clear();

        if ($active !== null) {
            $this->stopServer($active->key, $active->startedByUs);
        }

        $this->store->clear();

        info('Shutdown complete.');
    }

    private function stopServer(string $key, bool $startedByUs): void
    {
        if (! $startedByUs) {
            info("Leaving {$key} running — it was already running before app:serve started.");

            return;
        }

        $server = $this->selector->driver($key);

        if (! $server->isRunning()) {
            return;
        }

        if ($this->prompt->shouldStop($server)) {
            $server->stop();
            info("{$server->label()} stopped.");

            return;
        }

        info("Keeping {$server->label()} running.");
    }
}
