<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process;

use Igne\LaravelBootUp\Support\AtomicFile;
use Illuminate\Support\Collection;

use function Laravel\Prompts\warning;

/**
 * Persisted ledger of background processes started by the package,
 * surviving the boundary between app:serve and app:down.
 */
final class ProcessLedger
{
    public function __construct(private readonly string $path) {}

    public function record(ProcessRecord $record): void
    {
        $records = $this->all()
            ->reject(fn (ProcessRecord $existing): bool => $existing->pid === $record->pid)
            ->push($record);

        $this->write($records);
    }

    /**
     * @return Collection<int, ProcessRecord>
     */
    public function all(): Collection
    {
        if (! is_file($this->path)) {
            return new Collection;
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        if (! \is_array($decoded)) {
            $this->quarantine();

            return new Collection;
        }

        return (new Collection($decoded))
            ->filter(fn (mixed $entry): bool => \is_array($entry) && isset($entry['pid'], $entry['label'], $entry['command'], $entry['started_at']))
            ->map(fn (array $entry): ProcessRecord => ProcessRecord::fromArray($entry))
            ->values();
    }

    /**
     * @return Collection<int, ProcessRecord>
     */
    public function withLabel(string $label): Collection
    {
        return $this->all()
            ->filter(fn (ProcessRecord $record): bool => $record->label === $label)
            ->values();
    }

    public function forget(int $pid): void
    {
        $this->write($this->all()->reject(fn (ProcessRecord $record): bool => $record->pid === $pid));
    }

    public function clear(): void
    {
        AtomicFile::delete($this->path);
    }

    public function isEmpty(): bool
    {
        return $this->all()->isEmpty();
    }

    /**
     * Moves an undecodable ledger aside (a rename inside a read path, on
     * purpose): the evidence survives for inspection, and the warning
     * cannot repeat because the next read finds no file.
     */
    private function quarantine(): void
    {
        rename($this->path, $this->path.'.corrupt');

        warning('The boot-up process ledger was corrupt — moved to '.basename($this->path).'.corrupt and reset. Background processes it tracked may still be running.');
    }

    /**
     * @param  Collection<int, ProcessRecord>  $records
     */
    private function write(Collection $records): void
    {
        $payload = json_encode(
            $records->map(fn (ProcessRecord $record): array => $record->toArray())->values()->all(),
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );

        AtomicFile::write($this->path, $payload);
    }
}
