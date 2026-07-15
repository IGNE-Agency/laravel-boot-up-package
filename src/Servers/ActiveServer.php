<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

/**
 * The persisted record of the server an app:serve run is using, written
 * before the driver starts so shutdown always knows what to clean up.
 */
final readonly class ActiveServer
{
    public function __construct(
        public string $key,
        public bool $startedByUs,
        public int $servePid,
        public string $startedAt,
    ) {}

    /**
     * @param  array{key: string, started_by_us: bool, serve_pid: int, started_at: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) $data['key'],
            startedByUs: (bool) $data['started_by_us'],
            servePid: (int) $data['serve_pid'],
            startedAt: (string) $data['started_at'],
        );
    }

    /**
     * @return array{key: string, started_by_us: bool, serve_pid: int, started_at: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'started_by_us' => $this->startedByUs,
            'serve_pid' => $this->servePid,
            'started_at' => $this->startedAt,
        ];
    }
}
