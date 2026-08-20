<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

/**
 * One decision about a process the dev command could run: start it with
 * this command, leave whatever is registered alone, or keep it out of the
 * run — quietly when the project simply has no use for it, or with a note
 * when the user would otherwise wonder where the process went.
 */
final readonly class DevProcess
{
    private function __construct(
        public string $name,
        public bool $runs,
        public ?CommandLine $command,
        public ?string $skipReason,
    ) {}

    /**
     * Run this process under boot-up's own command, replacing a registration
     * the framework made by default.
     */
    public static function start(string $name, CommandLine $command): self
    {
        return new self($name, true, $command, null);
    }

    /**
     * Let the process run exactly as registered. Used where the framework's
     * own default is already the right command.
     */
    public static function keep(string $name): self
    {
        return new self($name, true, null, null);
    }

    /**
     * Keep the process out of the run. A reason is worth giving when the user
     * asked for something that did not happen; a project that never had
     * Horizon does not need to be told it is not starting Horizon.
     */
    public static function skip(string $name, ?string $reason = null): self
    {
        return new self($name, false, null, $reason);
    }
}
