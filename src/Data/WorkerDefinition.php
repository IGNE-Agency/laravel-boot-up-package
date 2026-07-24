<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

/**
 * Everything needed to start one tracked long-running worker: the ledger
 * label, the display name for terminal lines, the command to run, and
 * whether it runs in a terminal window or the background.
 */
final readonly class WorkerDefinition
{
    /**
     * @param  string  $runIn  'terminal' or 'background'
     * @param  list<string>  $tokens
     * @param  array<int|string, mixed>  $options  appended via CommandLine::withOptions()
     */
    public function __construct(
        public string $label,
        public string $name,
        public array $tokens,
        public string $runIn,
        public array $options = [],
    ) {}
}
