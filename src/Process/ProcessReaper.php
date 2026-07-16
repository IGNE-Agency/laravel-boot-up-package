<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process;

use Igne\LaravelBootUp\Support\Poller;
use Illuminate\Process\Factory;

use function Laravel\Prompts\warning;

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
        private readonly int $termGraceSeconds = 5,
        private readonly int $killGraceSeconds = 2,
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

    /**
     * Returns whether the process is confirmed gone; only then is it
     * forgotten. A survivor stays in the ledger so a later app:down can
     * try again.
     */
    public function reap(ProcessRecord $record): bool
    {
        if (! $this->isAlive($record)) {
            $this->ledger->forget($record->pid);

            return true;
        }

        $this->signalTree($record->pid, 'TERM');

        $terminated = $this->poller->until(
            fn (): bool => ! $this->isAlive($record),
            timeoutSeconds: $this->termGraceSeconds,
            intervalMs: 250,
        );

        if (! $terminated) {
            $this->signalTree($record->pid, 'KILL');

            $terminated = $this->poller->until(
                fn (): bool => ! $this->isAlive($record),
                timeoutSeconds: $this->killGraceSeconds,
                intervalMs: 250,
            );
        }

        if (! $terminated) {
            warning("Could not stop {$record->label} (pid {$record->pid}) — it stays in the ledger; stop it manually or re-run app:down.");

            return false;
        }

        $this->ledger->forget($record->pid);

        return true;
    }

    /**
     * Returns whether every tracked process is confirmed gone.
     */
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

    private function signalTree(int $pid, string $signal): void
    {
        // Children first (vite/esbuild under a watcher), then the parent.
        // A failing pkill just means no children; a failing parent kill is
        // caught by the isAlive() re-poll after each signal round.
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

        if ($runningBinary === '' || $runningBinary !== $recordedBinary) {
            return false;
        }

        // A matching binary alone is too loose after PID reuse: any `php`
        // would match any other `php`. The recorded arguments must appear too.
        $recordedArguments = trim((string) strstr($recorded, ' '));

        return $recordedArguments === '' || str_contains($running, $recordedArguments);
    }
}
