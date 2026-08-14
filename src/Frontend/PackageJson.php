<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Frontend;

use Igne\LaravelBootUp\Enums\PackageManager;

final class PackageJson
{
    public function __construct(private readonly string $path) {}

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

    public function hasScript(string $script): bool
    {
        $scripts = $this->read()['scripts'] ?? [];

        return \is_array($scripts) && \array_key_exists($script, $scripts);
    }

    /**
     * The package manager whose lockfile sits next to package.json, or null
     * when none does. With several lockfiles present the enum's case order
     * decides — a project in that state needs cleaning up either way.
     */
    public function lockedPackageManager(): ?PackageManager
    {
        foreach (PackageManager::cases() as $manager) {
            if (is_file(\dirname($this->path).'/'.$manager->lockfile())) {
                return $manager;
            }
        }

        return null;
    }

    /**
     * The package manager demanded by an engines sentinel such as
     * "please-use-bun", or null when the project does not pin one.
     */
    public function demandedPackageManager(): ?PackageManager
    {
        $engines = $this->read()['engines'] ?? [];

        if (! \is_array($engines)) {
            return null;
        }

        foreach ($engines as $constraint) {
            if (\is_string($constraint) && preg_match('/^please-use-(\w+)$/', $constraint, $matches) === 1) {
                return PackageManager::tryFrom($matches[1]);
            }
        }

        return null;
    }
}
