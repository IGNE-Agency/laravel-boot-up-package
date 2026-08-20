<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process;

use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Services\Poller;
use Illuminate\Process\Factory;

/**
 * Terminates ledger-tracked processes: TERM the whole descendant tree,
 * a grace period, then KILL. Guards against PID reuse by comparing the
 * running process's start time against the recorded snapshot before
 * signalling.
 */
final class ProcessReaper
{
    public function __construct(
        private readonly Factory $processes,
        private readonly ProcessLedger $ledger,
        private readonly Poller $poller,
        private readonly int $termGraceSeconds = 5,
        private readonly int $killGraceSeconds = 2,
    ) {}

    public function isAlive(ProcessRecord $record): bool
    {
        if (! $this->signal($record->pid, '-0')) {
            return false;
        }

        // The PID exists. Guard against PID reuse (after a reboot, or after
        // our process exited and the OS handed the number to something else):
        // a reused PID necessarily started AFTER we recorded ours, so a live
        // process that started later than the record is not ours. When the
        // start time cannot be read we err toward "alive" so a still-running
        // process is never silently abandoned.
        return $this->startedAfterRecord($record) !== true;
    }

    /**
     * Returns whether the process is confirmed gone; only then is it
     * forgotten. A survivor stays in the ledger so a later app:down can
     * try again.
     */
    public function reap(ProcessRecord $record): bool
    {
        if (! $this->isAlive($record)) {
            return $this->settle($record);
        }

        // Snapshot the whole tree ONCE, while everything is still alive and
        // parented, and reuse that exact target list for both signal passes.
        // Recomputing it after TERM would find nothing (the parent is gone and
        // its children have reparented to init), letting a TERM-surviving
        // descendant slip through the KILL pass.
        $targets = [...$this->descendants($record->pid), $record->pid];

        if (! $this->terminate($targets)) {
            terminal()->warning("Could not stop {$record->label} (pid {$record->pid}) — it stays in the ledger; stop it manually or re-run app:down.");

            return false;
        }

        return $this->settle($record);
    }

    /**
     * TERM the targets and wait; escalate to KILL for survivors. Returns
     * whether every target is confirmed gone.
     *
     * @param  list<int>  $targets
     */
    private function terminate(array $targets): bool
    {
        $this->signalAll($targets, 'TERM');

        $terminated = $this->poller->until(
            fn (): bool => $this->allGone($targets),
            timeoutSeconds: $this->termGraceSeconds,
            intervalMs: 250,
        );

        if ($terminated) {
            return true;
        }

        $this->signalAll($targets, 'KILL');

        return $this->poller->until(
            fn (): bool => $this->allGone($targets),
            timeoutSeconds: $this->killGraceSeconds,
            intervalMs: 250,
        );
    }

    /**
     * Returns whether every tracked process is confirmed gone.
     */
    /**
     * Whether anything recorded under this label is still alive. A label can
     * hold several records after a crash-and-restart, so one live process is
     * enough to count as running.
     */
    public function isRunning(string $label): bool
    {
        return $this->ledger->withLabel($label)
            ->contains(fn (ProcessRecord $record): bool => $this->isAlive($record));
    }

    /**
     * Stop everything recorded under this label.
     */
    public function stop(string $label): void
    {
        $this->ledger->withLabel($label)
            ->each(fn (ProcessRecord $record) => $this->reap($record));
    }

    public function reapAll(): bool
    {
        return $this->ledger->all()
            ->map(fn (ProcessRecord $record): bool => $this->reap($record))
            ->every(fn (bool $reaped): bool => $reaped);
    }

    /**
     * Drop ledger entries whose process is no longer alive, without
     * signalling anything.
     */
    public function prune(): void
    {
        $this->ledger->all()
            ->reject(fn (ProcessRecord $record): bool => $this->isAlive($record))
            ->each(fn (ProcessRecord $record) => $this->ledger->forget($record->pid));
    }

    /**
     * The process is gone: forget it.
     */
    private function settle(ProcessRecord $record): bool
    {
        $this->ledger->forget($record->pid);

        return true;
    }

    /**
     * Signal a pre-collected set of pids, deepest-first (macOS has no
     * setsid/process groups to lean on, so we cannot signal by group).
     *
     * @param  list<int>  $targets
     */
    private function signalAll(array $targets, string $signal): void
    {
        foreach ($targets as $target) {
            $this->signal($target, "-{$signal}");
        }
    }

    /**
     * Whether every pid in the snapshot is gone — a single survivor keeps the
     * reap alive so the KILL pass still runs against it.
     *
     * @param  list<int>  $targets
     */
    private function allGone(array $targets): bool
    {
        foreach ($targets as $target) {
            if ($this->signal($target, '-0')) {
                return false;
            }
        }

        return true;
    }

    /**
     * All descendant pids of the given pid, deepest-first.
     *
     * @return list<int>
     */
    private function descendants(int $pid): array
    {
        $descendants = [];

        foreach ($this->childrenOf($pid) as $child) {
            $descendants = [...$descendants, ...$this->descendants($child), $child];
        }

        return $descendants;
    }

    /**
     * @return list<int>
     */
    private function childrenOf(int $pid): array
    {
        $output = trim($this->processes
            ->command(['pgrep', '-P', (string) $pid])
            ->run()
            ->output());

        if ($output === '') {
            return [];
        }

        return str($output)
            ->explode(PHP_EOL)
            ->map(fn (string $line): int => (int) trim($line))
            ->filter(fn (int $child): bool => $child > 0)
            ->values()
            ->all();
    }

    private function signal(int $pid, string $signal): bool
    {
        return $this->processes
            ->command(['kill', $signal, (string) $pid])
            ->run()
            ->successful();
    }

    /**
     * Whether the live process holding this PID started clearly after the
     * record was written — the signature of a reused PID. Null when the start
     * time cannot be determined (treated as "not reused" by the caller).
     */
    private function startedAfterRecord(ProcessRecord $record): ?bool
    {
        $recordedAt = strtotime($record->startedAt);

        if ($recordedAt === false) {
            return null;
        }

        $elapsed = $this->elapsedSeconds($record->pid);

        if ($elapsed === null) {
            return null;
        }

        // etime is second-resolution and startedAt is stamped just after the
        // spawn, so allow a small tolerance before calling a PID reused.
        return (time() - $elapsed) > ($recordedAt + 5);
    }

    /**
     * Seconds the process holding this PID has been running, from
     * `ps -o etime=` (portable across macOS and Linux), or null if unknown.
     */
    private function elapsedSeconds(int $pid): ?int
    {
        $etime = trim($this->processes
            ->command(['ps', '-p', (string) $pid, '-o', 'etime='])
            ->run()
            ->output());

        if ($etime === '') {
            return null;
        }

        // Format: [[dd-]hh:]mm:ss
        $days = 0;

        if (str_contains($etime, '-')) {
            [$dayPart, $etime] = explode('-', $etime, 2);
            $days = (int) $dayPart;
        }

        $parts = array_map('intval', explode(':', $etime));

        $seconds = match (\count($parts)) {
            3 => $parts[0] * 3600 + $parts[1] * 60 + $parts[2],
            2 => $parts[0] * 60 + $parts[1],
            1 => $parts[0],
            default => null,
        };

        return $seconds === null ? null : $days * 86400 + $seconds;
    }
}
