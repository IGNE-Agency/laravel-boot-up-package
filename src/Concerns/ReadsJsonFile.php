<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

/**
 * exists()/read() for a project-owned JSON file such as package.json or
 * composer.json: absent or undecodable reads as an empty file, because a
 * project file someone hand-edited into bad JSON should degrade the answers
 * built on it, not crash the boot. Machine-written state files deliberately
 * do not share this contract — JsonStore quarantines those instead.
 *
 * The host class promotes a `private readonly string $path` in its own
 * constructor.
 */
trait ReadsJsonFile
{
    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        if (! $this->exists()) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        return \is_array($decoded) ? $decoded : [];
    }
}
