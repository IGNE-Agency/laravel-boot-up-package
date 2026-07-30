<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Process;

use Igne\LaravelBootUp\Data\CombinedService;
use Illuminate\Contracts\Process\InvokedProcess;

/**
 * The multiplexer's live state for one combined service: the running child
 * (or tail handle), its partial-line buffer, and the colored prefix its
 * lines carry. Mutable by design — this is bookkeeping, not a value.
 */
final class MultiplexedStream
{
    public string $buffer = '';

    public bool $live = false;

    public ?InvokedProcess $process = null;

    /** @var resource|null */
    public $tail = null;

    public int $pid = 0;

    public function __construct(
        public readonly CombinedService $service,
        public readonly string $prefix,
    ) {}
}
