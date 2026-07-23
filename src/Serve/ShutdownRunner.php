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
    /** Mirrors BuildOrWatchAssets::LABEL — the ledger label for the Vite watcher. */
    private const ASSET_WATCHER_LABEL = 'assets-watch';

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

        // Captured before reaping clears the ledger: a Vite watcher killed with
        // SIGKILL cannot remove its own public/hot marker.
        $hadAssetWatcher = $this->ledger->withLabel(self::ASSET_WATCHER_LABEL)->isNotEmpty();

        $this->ledger->all()->each(function (ProcessRecord $record): void {
            terminal()->info("Stopping {$record->label} (pid {$record->pid})...");
        });

        // The active-server record is always cleared, even if reaping a process
        // or stopping the server throws — a stale record would otherwise make
        // the next app:serve think a server it does not own is still active.
        try {
            // reap() already forgets confirmed-dead entries; only remove the
            // ledger file itself when nothing survived the signals.
            if ($this->reaper->reapAll()) {
                $this->ledger->clear();
            }

            if ($active !== null) {
                $this->stopServer($active->key, $active->startedByUs);
            }
        } finally {
            $this->store->clear();
            $this->cleanUpStaleHotFile($hadAssetWatcher);
        }

        terminal()->success('Shutdown complete.');
    }

    /**
     * Vite writes public/hot while its dev server runs and removes it on a
     * clean exit; a SIGKILLed watcher cannot, leaving the app pointing at a
     * now-dead dev server. Best-effort cleanup, only when we tracked a watcher.
     */
    private function cleanUpStaleHotFile(bool $hadAssetWatcher): void
    {
        if (! $hadAssetWatcher) {
            return;
        }

        $hot = base_path('public/hot');

        if (is_file($hot)) {
            @unlink($hot);
        }
    }

    private function stopServer(string $key, bool $startedByUs): void
    {
        $server = $this->selector->driver($key);

        if (! $server->isRunning()) {
            return;
        }

        // Even a server that was already running before app:serve is offered
        // for shutdown — the prompt is impact-aware and, for a server we did
        // not start, never stops by default.
        if (! $startedByUs) {
            terminal()->note("{$server->label()} was already running before app:serve started.");
        }

        if ($this->prompt->shouldStop($server, $startedByUs)) {
            // A failed stop is a warning, not a teardown-aborting error: the
            // rest of the shutdown (clearing state) must still complete.
            try {
                $server->stop();
                terminal()->success("{$server->label()} stopped.");
            } catch (\Throwable $exception) {
                terminal()->warning("Could not stop {$server->label()}: {$exception->getMessage()} — stop it manually.");
            }

            return;
        }

        terminal()->note("Keeping {$server->label()} running.");
    }
}
