<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools;

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

        return match ($this->status) {
            ToolStatus::Satisfied => $name,
            ToolStatus::Installed => "{$name} — installed",
            ToolStatus::Updated => "{$name} — updated",
            ToolStatus::SkippedSelfUpdating => "{$name} — manages its own updates",
            ToolStatus::Unverified => "{$name} — could not be verified (see warning above)",
        };
    }
}
