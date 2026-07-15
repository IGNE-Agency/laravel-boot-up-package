<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Herd;

/**
 * Read-only view of Herd's site registry: the directory of symlinks
 * (site name -> project path) that backs `herd links`. Reading the
 * symlinks directly lets us detect stale links pointing at moved
 * projects — `herd link` alone would leave them 404ing.
 */
final class HerdSites
{
    public function __construct(private readonly string $directory) {}

    /**
     * The path a site name is linked to, null when the name is unused.
     * Dead links (targets that no longer exist) still return their target.
     */
    public function linkedPath(string $name): ?string
    {
        $link = $this->directory.'/'.$name;

        if (! is_link($link)) {
            return null;
        }

        return $this->normalize((string) readlink($link));
    }

    /**
     * The site name already linked to this project, null when unlinked.
     */
    public function nameFor(string $projectPath): ?string
    {
        $project = $this->normalize($projectPath);

        foreach ($this->links() as $name => $target) {
            if ($target === $project) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> site name => linked path
     */
    public function links(): array
    {
        if (! is_dir($this->directory)) {
            return [];
        }

        $links = [];

        foreach (scandir($this->directory) ?: [] as $entry) {
            $path = $this->directory.'/'.$entry;

            if ($entry !== '.' && $entry !== '..' && is_link($path)) {
                $links[$entry] = $this->normalize((string) readlink($path));
            }
        }

        return $links;
    }

    private function normalize(string $path): string
    {
        return realpath($path) ?: rtrim($path, '/');
    }
}
