<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

use Igne\LaravelBootUp\Enums\RunMode;

final readonly class ProcessRecord
{
    public function __construct(
        public int $pid,
        public string $label,
        public string $command,
        public string $startedAt,
        public ?string $window = null,
        public ?RunMode $mode = null,
    ) {}

    /**
     * @param  array{pid: int, label: string, command: string, started_at: string, window?: string|null, mode?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pid: (int) $data['pid'],
            label: (string) $data['label'],
            command: (string) $data['command'],
            startedAt: (string) $data['started_at'],
            window: isset($data['window']) && $data['window'] !== null ? (string) $data['window'] : null,
            mode: isset($data['mode']) && $data['mode'] !== null ? RunMode::tryFrom((string) $data['mode']) : null,
        );
    }

    /**
     * Where this process's output goes, for the "started"/status lines: the
     * combined app:serve stream, its own terminal window when one was opened,
     * or the background log file. A terminal-run process writes no log file,
     * so advertising one would point the user at a path that does not exist.
     */
    public function outputLocation(): string
    {
        return match (true) {
            $this->mode === RunMode::Combined => 'output streams in the app:serve terminal',
            $this->window !== null => 'output is in its terminal window',
            default => "logs: storage/logs/boot-up/{$this->label}.log",
        };
    }

    /**
     * @return array{pid: int, label: string, command: string, started_at: string, window: string|null, mode: string|null}
     */
    public function toArray(): array
    {
        return [
            'pid' => $this->pid,
            'label' => $this->label,
            'command' => $this->command,
            'started_at' => $this->startedAt,
            'window' => $this->window,
            'mode' => $this->mode?->value,
        ];
    }
}
