<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Frontend;

use Igne\LaravelBootUp\Concerns\ReadsJsonFile;
use Igne\LaravelBootUp\Enums\PackageManager;

final class PackageJson
{
    use ReadsJsonFile;

    public function __construct(private readonly string $path) {}

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
        return collect(PackageManager::cases())
            ->first(fn (PackageManager $manager): bool => $this->matchedLockfile($manager) !== null);
    }

    /**
     * Which of this manager's lockfiles the project actually has, so a
     * message can name the file that was found rather than the one the
     * manager writes today.
     */
    public function matchedLockfile(PackageManager $manager): ?string
    {
        return collect($manager->lockfiles())
            ->first(fn (string $lockfile): bool => is_file(\dirname($this->path).'/'.$lockfile));
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
