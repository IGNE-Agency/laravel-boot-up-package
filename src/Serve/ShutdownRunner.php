<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Igne\LaravelBootUp\Contracts\HasResidualState;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Enums\BuiltInProcess;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
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
    /**
     * The dev process that runs the asset watcher, and so the one that may
     * have left a public/hot marker behind.
     */
    private const string ASSET_WATCHER_LABEL = BuiltInProcess::Vite->value;

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

        $this->tearDown($active);

        terminal()->success('Shutdown complete.');
    }

    /**
     * The active-server record is always cleared, even if reaping a process
     * throws — a stale record would otherwise make the next app:serve think
     * a server it does not own is still active. Clearing happens BEFORE the
     * stop-server prompt: Ctrl+C at a prompt calls exit(), which skips
     * finally blocks, so state cleared after the prompt would leak.
     * stopServer() receives everything it needs as arguments.
     */
    private function tearDown(?ActiveServerRecord $active): void
    {
        // Captured before reaping clears the ledger: a Vite watcher killed with
        // SIGKILL cannot remove its own public/hot marker.
        $hadAssetWatcher = $this->ledger->withLabel(self::ASSET_WATCHER_LABEL)->isNotEmpty();

        try {
            // reap() already forgets confirmed-dead entries; only remove the
            // ledger file itself when nothing survived the signals.
            if ($this->reaper->reapAll()) {
                $this->ledger->clear();
            }
        } finally {
            $this->store->clear();
            $this->cleanUpStaleHotFile($hadAssetWatcher);
        }

        if ($active !== null) {
            $this->stopServer($active->key, $active->startedByUs);
        }
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
        // A persisted key may belong to a custom driver that no longer
        // exists in config — the rest of the teardown already ran, so
        // reporting beats crashing.
        try {
            $server = $this->selector->driver($key);
        } catch (\Throwable) {
            terminal()->warning("The recorded server [{$key}] is not a known driver — stop it manually if it is still running.");

            return;
        }

        if (! $server->isRunning()) {
            $this->offerResidualCleanup($server, $startedByUs);

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

    /**
     * A server that is not running may still have left residual state behind
     * when its boot failed halfway (a failed `sail up` leaves stopped
     * containers and networks). Only offered for the server this run started.
     */
    private function offerResidualCleanup(Server $server, bool $startedByUs): void
    {
        if (! $startedByUs || ! $server instanceof HasResidualState || ! $server->hasResidualState()) {
            return;
        }

        terminal()->note("{$server->label()} is not running, but the last boot did not finish cleanly.");
        terminal()->info($server->residualStateImpact());

        if (! $this->prompt->shouldCleanUp($server)) {
            terminal()->note("Keeping {$server->label()}'s leftover resources in place.");

            return;
        }

        // Like a failed stop, a failed cleanup is a warning — the rest of the
        // shutdown (clearing state) must still complete.
        try {
            $server->cleanUpResidualState();
            terminal()->success("{$server->label()} cleaned up.");
        } catch (\Throwable $exception) {
            terminal()->warning("Could not clean up {$server->label()}: {$exception->getMessage()} — run the cleanup manually.");
        }
    }
}
