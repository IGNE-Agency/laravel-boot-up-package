<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process;

use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Services\JsonStore;
use Illuminate\Support\Collection;

/**
 * Persisted ledger of background processes started by the package,
 * surviving the boundary between app:serve and app:down.
 */
final class ProcessLedger
{
    private readonly JsonStore $store;

    public function __construct(string $path)
    {
        $this->store = new JsonStore(
            $path,
            'The boot-up process ledger was corrupt — moved to %s and reset. Background processes it tracked may still be running.',
        );
    }

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
        return (new Collection($this->store->read() ?? []))
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
        $this->store->clear();
    }

    public function isEmpty(): bool
    {
        return $this->all()->isEmpty();
    }

    /**
     * @param  Collection<int, ProcessRecord>  $records
     */
    private function write(Collection $records): void
    {
        $this->store->write($records->map(fn (ProcessRecord $record): array => $record->toArray())->values()->all());
    }
}
