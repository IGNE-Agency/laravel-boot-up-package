<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process;

use Closure;
use Igne\LaravelBootUp\Data\CombinedService;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Enums\RunMode;
use Igne\LaravelBootUp\Enums\StreamColor;
use Igne\LaravelBootUp\Serve\CombinedRunPlan;
use Illuminate\Process\Factory;

/**
 * Runs the combined services as children of the foreground app:serve and
 * interleaves their output, one colored `[name]`-prefixed line at a time —
 * the package's own `npx concurrently`. Tail entries follow a log file
 * (the detached `php artisan serve`) instead of starting a process.
 *
 * The loop ends when stop() is called (the Ctrl+C trap) or every child has
 * exited; reaping survivors stays ShutdownRunner's job.
 */
final class OutputMultiplexer
{
    private bool $stopped = false;

    /** @var Closure(string): void */
    private readonly Closure $write;

    public function __construct(
        private readonly Factory $processes,
        private readonly ProcessLedger $ledger,
        private readonly int $pollIntervalMicroseconds = 50_000,
        ?Closure $write = null,
    ) {
        $this->write = $write ?? static function (string $text): void {
            fwrite(STDOUT, $text);
        };
    }

    public function stream(CombinedRunPlan $plan): void
    {
        $this->stopped = false;

        $streams = $this->open($plan->services());

        while (! $this->stopped && $this->hasLiveProcesses($streams)) {
            foreach ($streams as $stream) {
                $this->pump($stream);
            }

            // pcntl's async signals fire during usleep, so the Ctrl+C trap
            // runs promptly between ticks.
            usleep($this->pollIntervalMicroseconds);
        }

        foreach ($streams as $stream) {
            $this->close($stream);
        }
    }

    /**
     * End the stream loop; children keep running until ShutdownRunner reaps
     * them (or already died with the terminal's SIGINT).
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * @param  list<CombinedService>  $services
     * @return list<MultiplexedStream>
     */
    private function open(array $services): array
    {
        $width = collect($services)->map(fn (CombinedService $service): int => strlen($service->name))->max() ?? 0;
        $colors = $this->assignColors($services);

        return collect($services)
            ->map(function (CombinedService $service, int $index) use ($width, $colors): MultiplexedStream {
                $stream = new MultiplexedStream($service, $this->prefix($service->name, $colors[$index], $width));

                $service->isProcess()
                    ? $this->startProcess($stream)
                    : $this->openTail($stream);

                return $stream;
            })
            ->values()
            ->all();
    }

    /**
     * Requested colors are honored and reserved; the rest of the services
     * draw the unused palette colors in stream order, then round-robin over
     * the whole palette once every color has appeared.
     *
     * @param  list<CombinedService>  $services
     * @return list<StreamColor>
     */
    private function assignColors(array $services): array
    {
        $palette = StreamColor::cases();
        $reserved = array_filter(array_map(fn (CombinedService $service): ?StreamColor => $service->color, $services));
        $pool = array_values(array_filter($palette, fn (StreamColor $color): bool => ! \in_array($color, $reserved, true)));

        $assigned = [];
        $overflow = 0;

        foreach ($services as $service) {
            $assigned[] = $service->color
                ?? array_shift($pool)
                ?? $palette[$overflow++ % \count($palette)];
        }

        return $assigned;
    }

    /**
     * `[name]` padded so every line's output starts in the same column.
     */
    private function prefix(string $name, StreamColor $color, int $width): string
    {
        $padded = str_pad("[{$name}]", $width + 2);

        return terminal()->hex($color->value, $padded);
    }

    private function startProcess(MultiplexedStream $stream): void
    {
        $service = $stream->service;

        $stream->process = PendingProcessBuilder::build($this->processes, $service->command)
            ->start(null, fn (string $type, string $buffer) => $this->emit($stream, $buffer));

        $stream->live = true;
        $stream->pid = (int) ($stream->process->id() ?? 0);

        // Ledger-tracked like every other worker: app:status sees it, and
        // app:down (or the next boot's prune) reaps orphans if this process
        // is killed without cleanup.
        $this->ledger->record(new ProcessRecord(
            pid: $stream->pid,
            label: $service->label,
            command: $service->command->toString(),
            startedAt: date(DATE_ATOM),
            mode: RunMode::Combined,
        ));
    }

    private function openTail(MultiplexedStream $stream): void
    {
        $logFile = (string) $stream->service->logFile;

        if (! is_file($logFile)) {
            return;
        }

        $handle = fopen($logFile, 'r');

        if ($handle === false) {
            return;
        }

        fseek($handle, 0, SEEK_END);
        $stream->tail = $handle;
    }

    private function pump(MultiplexedStream $stream): void
    {
        if ($stream->tail !== null) {
            $this->pumpTail($stream);

            return;
        }

        if (! $stream->live || $stream->process === null) {
            return;
        }

        // running() pumps the output callback with anything new.
        if ($stream->process->running()) {
            return;
        }

        $this->settleExited($stream);
    }

    private function pumpTail(MultiplexedStream $stream): void
    {
        // A plain-file stream latches EOF; the no-op seek clears it so
        // appends written since the last tick are readable.
        fseek($stream->tail, 0, SEEK_CUR);
        $chunk = stream_get_contents($stream->tail);

        if (\is_string($chunk) && $chunk !== '') {
            $this->emit($stream, $chunk);
        }
    }

    /**
     * A child exited mid-stream: drain its remaining output, tell the user,
     * and forget its ledger record — the others keep running.
     */
    private function settleExited(MultiplexedStream $stream): void
    {
        $result = $stream->process->wait();
        $stream->live = false;

        $this->flush($stream);
        $this->ledger->forget($stream->pid);

        $name = $stream->service->name;
        $code = $result->exitCode();

        $code === 0
            ? terminal()->note("[{$name}] exited — remaining services keep running.")
            : terminal()->warning("[{$name}] exited with code {$code} — remaining services keep running.");
    }

    private function emit(MultiplexedStream $stream, string $chunk): void
    {
        $stream->buffer .= $chunk;

        while (($newline = strpos($stream->buffer, "\n")) !== false) {
            $line = rtrim(substr($stream->buffer, 0, $newline), "\r");
            $stream->buffer = substr($stream->buffer, $newline + 1);

            ($this->write)("{$stream->prefix} {$line}".PHP_EOL);
        }
    }

    private function flush(MultiplexedStream $stream): void
    {
        if ($stream->buffer !== '') {
            $this->emit($stream, "\n");
        }
    }

    private function close(MultiplexedStream $stream): void
    {
        if ($stream->tail !== null) {
            $this->pumpTail($stream);
            fclose($stream->tail);
            $stream->tail = null;
        }

        $this->flush($stream);
    }

    /**
     * @param  list<MultiplexedStream>  $streams
     */
    private function hasLiveProcesses(array $streams): bool
    {
        return collect($streams)->contains(fn (MultiplexedStream $stream): bool => $stream->live);
    }
}
