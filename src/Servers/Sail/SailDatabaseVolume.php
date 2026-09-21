<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Sail;

use Igne\LaravelBootUp\Environment\EnvFile;
use Illuminate\Contracts\Config\Repository;

/**
 * The Docker volume behind Sail's database service.
 *
 * It matters because the database image bakes DB_DATABASE / DB_USERNAME /
 * DB_PASSWORD into the volume the first time it is created — afterwards the
 * credentials live inside the volume and .env changes never reach them. So
 * a boot needs two answers: is the volume about to be created fresh (the
 * one moment credentials can still be chosen), and did it exist before this
 * boot (the only case where rejected credentials mean a stale volume rather
 * than a mistake this boot made itself).
 *
 * Every lookup degrades to null/unknown when the compose config cannot be
 * read — the callers all treat that as "stand down", never as an error.
 */
final class SailDatabaseVolume
{
    private ?bool $existedBeforeBoot = null;

    public function __construct(
        private readonly SailCompose $compose,
        private readonly Docker $docker,
        private readonly EnvFile $envFile,
        private readonly Repository $laravelConfig,
    ) {}

    /**
     * The volume's actual Docker name, or null when the compose config does
     * not pin one down (unreadable config, sqlite, bind mounts only).
     */
    public function name(): ?string
    {
        $definition = $this->serviceDefinition();

        if ($definition === null) {
            return null;
        }

        $source = $this->firstNamedVolumeSource($definition);

        if ($source === null) {
            return null;
        }

        // A top-level `name:` override wins; otherwise Docker prefixes the
        // compose project name, which the resolved config states outright.
        $volume = $this->compose->volumes()[$source] ?? null;

        if (\is_array($volume) && \is_string($volume['name'] ?? null) && $volume['name'] !== '') {
            return $volume['name'];
        }

        $project = $this->compose->projectName();

        return $project === null ? null : "{$project}_{$source}";
    }

    /**
     * Whether the next `sail up` would create the database volume from
     * scratch — the only moment its credentials can still be chosen.
     */
    public function isFresh(): bool
    {
        $name = $this->name();

        return $name !== null && ! $this->docker->volumeExists($name);
    }

    /**
     * Remember, before anything is started, whether the volume already
     * existed. The first capture wins — prepare() may run more than once.
     */
    public function captureExistedBeforeBoot(): void
    {
        $this->existedBeforeBoot ??= ! $this->isFresh();
    }

    /**
     * Defaults to true when never captured (a running server skips the
     * capture): "existed before" is the conservative answer, because it is
     * the one that keeps recovery available without ever claiming this boot
     * created the volume.
     */
    public function existedBeforeBoot(): bool
    {
        return $this->existedBeforeBoot ?? true;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function serviceDefinition(): ?array
    {
        $services = $this->compose->services();

        foreach ($this->candidateServices() as $candidate) {
            $definition = $services[$candidate] ?? null;

            if (\is_array($definition)) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * The service names worth trying for the configured connection: Sail's
     * conventional service for the driver first, then whatever DB_HOST names
     * (under Sail that is the service name by construction).
     *
     * @return list<string>
     */
    private function candidateServices(): array
    {
        $connection = $this->envFile->valueOr('DB_CONNECTION', (string) $this->laravelConfig->get('database.default'));

        if ($connection === 'sqlite') {
            return [];
        }

        $conventional = match ($connection) {
            'pgsql' => 'pgsql',
            'mariadb' => 'mariadb',
            default => 'mysql',
        };

        return array_values(array_unique(array_filter(
            [$conventional, $this->envFile->valueOr('DB_HOST', '')],
            static fn (string $service): bool => $service !== '',
        )));
    }

    /**
     * @param  array<array-key, mixed>  $definition
     */
    private function firstNamedVolumeSource(array $definition): ?string
    {
        $mounts = $definition['volumes'] ?? null;

        if (! \is_array($mounts)) {
            return null;
        }

        foreach ($mounts as $mount) {
            if (\is_array($mount)
                && ($mount['type'] ?? null) === 'volume'
                && \is_string($mount['source'] ?? null)
                && $mount['source'] !== '') {
                return $mount['source'];
            }
        }

        return null;
    }
}
