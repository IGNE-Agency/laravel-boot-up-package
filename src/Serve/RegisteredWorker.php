<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Igne\LaravelBootUp\Contracts\Worker;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Enums\RunMode;
use Igne\LaravelBootUp\Enums\StreamColor;

/**
 * A BootCommands registration dressed as a Worker so the launcher treats
 * it exactly like the built-ins: rewritten for the server, ledger-tracked,
 * queued for the combined stream or started in a terminal/background. The
 * registration's name is both the ledger label and the stream prefix.
 */
final readonly class RegisteredWorker implements Worker
{
    public function __construct(
        private PendingBootProcess $process,
        private CommandLine $command,
    ) {}

    public function label(): string
    {
        return $this->process->name();
    }

    public function name(): string
    {
        return "Registered command [{$this->process->name()}]";
    }

    public function command(): CommandLine
    {
        return $this->command;
    }

    public function runIn(): RunMode
    {
        return $this->process->runIn();
    }

    public function streamName(): string
    {
        return $this->process->name();
    }

    public function streamColor(): ?StreamColor
    {
        return $this->process->pickedColor();
    }
}
