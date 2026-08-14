<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Enums\BootProcessKind;
use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Enums\RegistrationSource;
use Igne\LaravelBootUp\Enums\RunMode;
use Igne\LaravelBootUp\Enums\StreamColor;
use Igne\LaravelBootUp\Enums\StreamPosition;

/**
 * One registered dev process, still being described: BootCommands returns
 * this so a provider can chain color, run mode, environment, and stream
 * placement onto the registration. Deliberately mutable — it is a builder
 * the registry holds, not a value that travels.
 */
final class PendingBootProcess
{
    private ?StreamColor $color = null;

    private RunMode $runIn = RunMode::Combined;

    /** @var array<string, string> */
    private array $env = [];

    private ?string $directory = null;

    private ?StreamPosition $position = null;

    private ?string $positionTarget = null;

    public function __construct(
        private readonly BootProcessKind $kind,
        private readonly string $command,
        private readonly string $name,
        private readonly RegistrationSource $source,
    ) {}

    public function color(StreamColor $color): self
    {
        $this->color = $color;

        return $this;
    }

    public function blue(): self
    {
        return $this->color(StreamColor::Blue);
    }

    public function purple(): self
    {
        return $this->color(StreamColor::Purple);
    }

    public function pink(): self
    {
        return $this->color(StreamColor::Pink);
    }

    public function orange(): self
    {
        return $this->color(StreamColor::Orange);
    }

    public function green(): self
    {
        return $this->color(StreamColor::Green);
    }

    public function yellow(): self
    {
        return $this->color(StreamColor::Yellow);
    }

    /**
     * Open the process in its own terminal window instead of the combined
     * stream.
     */
    public function inTerminal(): self
    {
        $this->runIn = RunMode::Terminal;

        return $this;
    }

    /**
     * Detach the process into the background (logged under
     * storage/logs/boot-up) instead of the combined stream.
     */
    public function inBackground(): self
    {
        $this->runIn = RunMode::Background;

        return $this;
    }

    /**
     * @param  array<string, string>  $env
     */
    public function env(array $env): self
    {
        $this->env = [...$this->env, ...$env];

        return $this;
    }

    public function in(string $directory): self
    {
        $this->directory = $directory;

        return $this;
    }

    public function first(): self
    {
        return $this->position(StreamPosition::First);
    }

    public function last(): self
    {
        return $this->position(StreamPosition::Last);
    }

    public function before(string $streamName): self
    {
        return $this->position(StreamPosition::Before, $streamName);
    }

    public function after(string $streamName): self
    {
        return $this->position(StreamPosition::After, $streamName);
    }

    private function position(StreamPosition $position, ?string $target = null): self
    {
        $this->position = $position;
        $this->positionTarget = $target;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function source(): RegistrationSource
    {
        return $this->source;
    }

    public function pickedColor(): ?StreamColor
    {
        return $this->color;
    }

    public function runIn(): RunMode
    {
        return $this->runIn;
    }

    public function placement(): ?StreamPosition
    {
        return $this->position;
    }

    public function placementTarget(): ?string
    {
        return $this->positionTarget;
    }

    /**
     * Whether launching needs the project's package manager resolved.
     */
    public function usesPackageManager(): bool
    {
        return $this->kind->usesPackageManager();
    }

    /**
     * The runnable command, resolved at launch time so the package manager
     * lookup (and its one-time selection note) happens during the boot, not
     * at provider registration.
     */
    public function commandLine(PackageManager $packageManager): CommandLine
    {
        $command = $this->kind->commandLine($this->command, $packageManager);

        if ($this->env !== []) {
            $command = $command->withEnv($this->env);
        }

        if ($this->directory !== null) {
            $command = $command->inDirectory($this->directory);
        }

        return $command;
    }
}
