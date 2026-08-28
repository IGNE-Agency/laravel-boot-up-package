<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Frontend;

/**
 * Vite's public/hot marker: written when its dev server starts listening and
 * removed again on a clean exit. Its presence is what makes `@vite` emit
 * dev-server tags instead of reading the build manifest, so it is the one
 * signal that says the assets a page asks for can actually be served.
 *
 * Two callers need it for opposite reasons — the deferred browser waits for
 * it to appear, the shutdown removes what a killed watcher left behind — and
 * a marker addressed by two hardcoded paths is a marker with two meanings.
 */
final class ViteHotFile
{
    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    /**
     * PHP's stat cache caches misses too, so a poll loop that asked once
     * before the watcher started would never see the file appear.
     */
    public function exists(): bool
    {
        clearstatcache(true, $this->path);

        return is_file($this->path);
    }

    /**
     * Best-effort: a marker that cannot be removed is not worth failing a
     * boot or a teardown over.
     */
    public function remove(): void
    {
        if ($this->exists()) {
            @unlink($this->path);
        }
    }
}
