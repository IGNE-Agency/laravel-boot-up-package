<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Sail;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Process\ProcessRunner;

/**
 * The project's resolved compose configuration, read once per process.
 *
 * Compose itself is asked rather than the file parsed: it resolves
 * `${FORWARD_DB_PORT:-3306}` against the project's own .env, and it knows
 * about override files, custom project names and services this package has
 * never heard of. Every reader degrades to "nothing" when the config cannot
 * be read — a pre-flight check has no business failing a boot.
 */
final class SailCompose
{
    /** @var array<array-key, mixed>|null */
    private ?array $config = null;

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Sail $sail,
    ) {}

    /**
     * The resolved services, or [] whenever they cannot be read — which
     * callers must treat as "do not check", not "nothing to check".
     *
     * @return array<array-key, mixed>
     */
    public function services(): array
    {
        $services = $this->read()['services'] ?? null;

        return \is_array($services) ? $services : [];
    }

    /**
     * The top-level named volumes, keyed by their compose name.
     *
     * @return array<array-key, mixed>
     */
    public function volumes(): array
    {
        $volumes = $this->read()['volumes'] ?? null;

        return \is_array($volumes) ? $volumes : [];
    }

    /**
     * The compose project name — the prefix Docker gives every resource the
     * project creates. Compose resolves it from COMPOSE_PROJECT_NAME, a
     * top-level `name:` key or the directory, so it is read, never guessed.
     */
    public function projectName(): ?string
    {
        $name = $this->read()['name'] ?? null;

        return \is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function read(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        if (! $this->sail->isConfigured()) {
            return [];
        }

        // SAIL_SKIP_CHECKS keeps sail's wrapper from running its own
        // pre-flight, which shuts down exited containers -- a read must not
        // do that. Only stdout is parsed: compose writes its
        // "variable is not set" warnings to stderr.
        $result = $this->runner->runSilently(
            CommandLine::make('./vendor/bin/sail config --format json')
                ->withEnv(['SAIL_SKIP_CHECKS' => '1']),
        );

        if (! $result->successful()) {
            return [];
        }

        $config = json_decode($result->output(), true);

        if (! \is_array($config)) {
            return [];
        }

        // Only a successful read is memoized: one taken before the compose
        // file was scaffolded must not poison the reads that follow it.
        return $this->config = $config;
    }
}
