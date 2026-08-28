<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Services;

use Igne\LaravelBootUp\Data\GeneratedFile;

/**
 * Writes generated files into the project after one all-or-nothing
 * overwrite confirmation. Generated files reference each other (a pipeline
 * calls its scripts), so a partial write could leave them out of sync;
 * declining writes nothing.
 */
final class GeneratedFilePublisher
{
    public function __construct(private readonly string $basePath) {}

    /**
     * @param  list<GeneratedFile>  $files
     * @return bool false when the user declined the overwrite — nothing was written
     */
    public function publish(array $files, bool $force = false): bool
    {
        if (! $force && ! $this->confirmOverwrites($files)) {
            terminal()->warning('Nothing written — declined to overwrite existing files.');

            return false;
        }

        foreach ($files as $file) {
            $this->write($file);
        }

        return true;
    }

    /**
     * @param  list<GeneratedFile>  $files
     */
    private function confirmOverwrites(array $files): bool
    {
        $existing = collect($files)
            ->map(fn (GeneratedFile $file): string => $file->path)
            ->filter(fn (string $path): bool => is_file("{$this->basePath}/{$path}"))
            ->values();

        return match ($existing->count()) {
            0 => true,
            1 => terminal()->confirm("{$existing->first()} already exists. Overwrite it?", default: false),
            default => terminal()->confirm(
                "Overwrite these {$existing->count()} existing files? {$existing->implode(', ')}",
                default: false,
            ),
        };
    }

    private function write(GeneratedFile $file): void
    {
        $path = "{$this->basePath}/{$file->path}";

        AtomicFile::write($path, $file->contents);

        if ($file->executable) {
            chmod($path, 0755);
        }

        terminal()->success("Wrote {$file->path}.");
    }
}
