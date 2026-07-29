<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

use Igne\LaravelBootUp\Enums\RunMode;

/**
 * Everything needed to start one tracked long-running worker: the ledger
 * label, the display name for terminal lines, the command to run, where its
 * output lives, and the short prefix it streams under in combined mode.
 */
final readonly class WorkerDefinition
{
    /**
     * @param  list<string>  $tokens
     * @param  string|null  $streamAs  combined-stream prefix; defaults to the label
     * @param  array<int|string, mixed>  $options  appended via CommandLine::withOptions()
     */
    public function __construct(
        public string $label,
        public string $name,
        public array $tokens,
        public RunMode $runIn,
        public ?string $streamAs = null,
        public array $options = [],
    ) {}

    public function streamName(): string
    {
        return $this->streamAs ?? $this->label;
    }
}
