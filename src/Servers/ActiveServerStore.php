<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Services\AtomicFile;

/**
 * Persists the active-server record across the app:serve / app:down
 * boundary as atomic JSON in storage/framework/boot-up.
 */
final class ActiveServerStore
{
    public function __construct(private readonly string $path) {}

    public function remember(ActiveServerRecord $server): void
    {
        AtomicFile::write($this->path, (string) json_encode($server->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function current(): ?ActiveServerRecord
    {
        if (! is_file($this->path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        if (! \is_array($decoded) || ! isset($decoded['key'], $decoded['started_by_us'], $decoded['serve_pid'], $decoded['started_at'])) {
            $this->quarantine();

            return null;
        }

        return ActiveServerRecord::fromArray($decoded);
    }

    public function clear(): void
    {
        AtomicFile::delete($this->path);
    }

    /**
     * Moves an undecodable record aside (a rename inside a read path, on
     * purpose): the evidence survives for inspection, and the warning
     * cannot repeat because the next read finds no file.
     */
    private function quarantine(): void
    {
        rename($this->path, "{$this->path}.corrupt");

        $file = basename($this->path);
        terminal()->warning("The boot-up active-server record was corrupt — moved to {$file}.corrupt and reset. A previously started server may still be running.");
    }
}
