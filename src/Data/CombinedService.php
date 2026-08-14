<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

use Igne\LaravelBootUp\Enums\StreamColor;

/**
 * One entry in the combined output stream: either a process the multiplexer
 * starts itself, or a log file it tails (the detached `php artisan serve`
 * reports as [server] this way). Exactly one of command/logFile is set; a
 * null color takes whatever the palette assigns.
 */
final readonly class CombinedService
{
    private function __construct(
        public string $label,
        public string $name,
        public ?CommandLine $command,
        public ?string $logFile,
        public ?StreamColor $color,
    ) {}

    public static function process(string $label, string $name, CommandLine $command, ?StreamColor $color = null): self
    {
        return new self($label, $name, $command, null, $color);
    }

    public static function tail(string $label, string $name, string $logFile): self
    {
        return new self($label, $name, null, $logFile, null);
    }

    public function isProcess(): bool
    {
        return $this->command !== null;
    }
}
