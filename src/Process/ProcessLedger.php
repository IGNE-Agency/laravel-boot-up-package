<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process;

use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Services\JsonStore;
use Illuminate\Support\Collection;

/**
 * Persisted ledger of background processes started by the package, surviving
 * the boundary between the command that started them and app:down.
 *
 * Only detached processes are in here: the ones `php artisan dev` streams
 * belong to the terminal UI, which starts, restarts and reaps them itself.
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
        $entries = new Collection($this->store->read() ?? []);

        $usable = $entries
            ->filter(fn (mixed $entry): bool => \is_array($entry) && isset($entry['pid'], $entry['label'], $entry['command'], $entry['started_at']))
            ->values();

        // Unlike the active-server record, a malformed entry does not condemn
        // the file: quarantining it would orphan every process the other
        // entries still track. Dropping them quietly would be worse, though --
        // something started and is now unaccounted for.
        if ($usable->count() !== $entries->count()) {
            $dropped = $entries->count() - $usable->count();

            terminal()->warning("Ignored {$dropped} unreadable process record(s); those processes are no longer tracked and may need stopping by hand.");
        }

        return $usable->map(fn (array $entry): ProcessRecord => ProcessRecord::fromArray($entry));
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
