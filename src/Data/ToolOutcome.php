<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

use Igne\LaravelBootUp\Enums\ToolStatus;

/**
 * The structured result of one tool check, for the grouped dependencies
 * summary — data instead of parsed terminal strings.
 */
final readonly class ToolOutcome
{
    public function __construct(
        public string $label,
        public ToolStatus $status,
        public ?string $version = null,
    ) {}

    public function describe(): string
    {
        $name = $this->version === null ? $this->label : "{$this->label} {$this->version}";
        $status = $this->status->label();

        return $status === '' ? $name : "{$name} — {$status}";
    }
}
