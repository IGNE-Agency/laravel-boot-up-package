<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process;

use Igne\LaravelBootUp\Support\Poller;
use Illuminate\Process\Factory;

/**
 * Terminates ledger-tracked processes: TERM (including children), a grace
 * period, then KILL. Guards against PID reuse by comparing the running
 * command against the recorded snapshot before signalling.
 */
final class ProcessReaper
{
    public function __construct(
        private readonly Factory $processes,
        private readonly ProcessLedger $ledger,
        private readonly Poller $poller,
    ) {}

    public function isAlive(ProcessRecord $record): bool
    {
        if (! $this->signal($record->pid, '-0')) {
            return false;
        }

        $running = trim($this->processes
            ->command(['ps', '-p', (string) $record->pid, '-o', 'command='])
            ->run()
            ->output());

        return $running !== '' && $this->commandsMatch($running, $record->command);
    }

    public function reap(ProcessRecord $record): void
    {
        if (! $this->isAlive($record)) {
            $this->ledger->forget($record->pid);

            return;
        }

        $this->signalTree($record->pid, 'TERM');

        $terminated = $this->poller->until(
            fn (): bool => ! $this->isAlive($record),
            timeoutSeconds: 5,
            intervalMs: 250,
        );

        if (! $terminated) {
            $this->signalTree($record->pid, 'KILL');
        }

        $this->ledger->forget($record->pid);
    }

    public function reapAll(): void
    {
        $this->ledger->all()->each(fn (ProcessRecord $record) => $this->reap($record));
    }

    private function signalTree(int $pid, string $signal): void
    {
        // Children first (vite/esbuild under a watcher), then the parent.
        $this->processes->command(['pkill', "-{$signal}", '-P', (string) $pid])->run();
        $this->signal($pid, "-{$signal}");
    }

    private function signal(int $pid, string $signal): bool
    {
        return $this->processes
            ->command(['kill', $signal, (string) $pid])
            ->run()
            ->successful();
    }

    /**
     * PID-reuse guard: after a reboot the recorded PID may belong to an
     * unrelated process. Only treat it as ours when the running command
     * resembles the recorded one.
     */
    private function commandsMatch(string $running, string $recorded): bool
    {
        if (str_contains($running, $recorded)) {
            return true;
        }

        $runningBinary = basename(strtok($running, ' ') ?: '');
        $recordedBinary = basename(strtok($recorded, ' ') ?: '');

        return $runningBinary !== '' && $runningBinary === $recordedBinary;
    }
}
