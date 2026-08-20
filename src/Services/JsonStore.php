<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Services;

/**
 * The persistence shape the boot-up state files share: pretty-printed
 * JSON written atomically, decoded defensively, and quarantined when
 * unreadable — renamed to .corrupt so the evidence survives for
 * inspection and the warning cannot repeat (the next read finds no file).
 */
final class JsonStore
{
    /**
     * @param  string  $corruptWarning  sprintf template; %s receives the .corrupt file name
     */
    public function __construct(
        private readonly string $path,
        private readonly string $corruptWarning,
    ) {}

    /**
     * The decoded array; null when the file is absent or was just
     * quarantined as undecodable.
     *
     * @return array<mixed>|null
     */
    public function read(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        if (! \is_array($decoded)) {
            $this->quarantine();

            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<mixed>  $payload
     */
    public function write(array $payload): void
    {
        AtomicFile::write($this->path, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function clear(): void
    {
        AtomicFile::delete($this->path);
    }

    /**
     * Move the file aside (a rename inside a read path, on purpose) —
     * also for callers whose decoded payload fails SEMANTIC validation.
     */
    public function quarantine(): void
    {
        $file = basename($this->path);

        // Non-fatal on purpose: this runs inside a read, and refusing to boot
        // because an unreadable file could not be renamed would be worse than
        // saying so. The message has to stay honest either way.
        if (! rename($this->path, "{$this->path}.corrupt")) {
            terminal()->warning("[{$file}] could not be read and could not be moved aside; delete it by hand.");

            return;
        }

        terminal()->warning(sprintf($this->corruptWarning, "{$file}.corrupt"));
    }
}
