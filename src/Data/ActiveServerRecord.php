<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

/**
 * The persisted record of the server this project is set up with, written
 * before the driver starts so shutdown always knows what to clean up.
 *
 * It outlives the run that wrote it: app:up leaves the server running,
 * `dev` reads the record to know which driver serves the project, and
 * app:down clears it. $setupPid is the app:up run that wrote it — live only
 * while that command is still working, which is how a second one is kept out.
 */
final readonly class ActiveServerRecord
{
    public function __construct(
        public string $key,
        public bool $startedByUs,
        public int $setupPid,
        public string $startedAt,
    ) {}

    /**
     * @param  array{key: string, started_by_us: bool, setup_pid: int, started_at: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) $data['key'],
            startedByUs: (bool) $data['started_by_us'],
            setupPid: (int) $data['setup_pid'],
            startedAt: (string) $data['started_at'],
        );
    }

    /**
     * @return array{key: string, started_by_us: bool, setup_pid: int, started_at: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'started_by_us' => $this->startedByUs,
            'setup_pid' => $this->setupPid,
            'started_at' => $this->startedAt,
        ];
    }
}
