<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

use Igne\LaravelBootUp\Process\ProcessRunner;

final readonly class ProcessRecord
{
    public function __construct(
        public int $pid,
        public string $label,
        public string $command,
        public string $startedAt,
    ) {}

    /**
     * @param  array{pid: int, label: string, command: string, started_at: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pid: (int) $data['pid'],
            label: (string) $data['label'],
            command: (string) $data['command'],
            startedAt: (string) $data['started_at'],
        );
    }

    /**
     * Where this process's output goes, for the "started"/status lines.
     * Every tracked process is detached, so that is always its log file.
     */
    public function outputLocation(): string
    {
        return 'logs: storage/'.ProcessRunner::LOG_SUBDIRECTORY."/{$this->label}.log";
    }

    /**
     * @return array{pid: int, label: string, command: string, started_at: string}
     */
    public function toArray(): array
    {
        return [
            'pid' => $this->pid,
            'label' => $this->label,
            'command' => $this->command,
            'started_at' => $this->startedAt,
        ];
    }
}
