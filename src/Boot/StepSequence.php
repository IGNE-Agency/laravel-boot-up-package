<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Boot;

use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Label;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Contracts\DescribesProgress;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Data\StepDescriptor;
use Igne\LaravelBootUp\Data\StepEntry;
use Igne\LaravelBootUp\Deploy\Steps\InstallComposerDependencies;
use Igne\LaravelBootUp\Enums\BootStage;
use Igne\LaravelBootUp\Environment\Steps\EnsureEnvFile;
use Igne\LaravelBootUp\Environment\Steps\EnsureLocalEnvironment;
use Igne\LaravelBootUp\Environment\Steps\GenerateAppKey;
use Igne\LaravelBootUp\Frontend\Steps\InstallFrontendDependencies;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * What the configured boot pipeline is about to do: each entry parsed and
 * assigned a stage plus a progress label, and a concise options-aware
 * summary for the "What dev will do" block. Only BootOptions gate
 * summary lines — config/context skip logic stays inside the steps.
 */
final readonly class StepSequence
{
    /**
     * @param  list<StepDescriptor>  $steps
     * @param  list<string>  $devProcesses
     */
    private function __construct(
        public array $steps,
        private BootOptions $options,
        private ?string $serverLabel,
        private array $devProcesses,
    ) {}

    /**
     * @param  list<string>  $configuredSteps
     * @param  list<string>  $devProcesses  names of the dev processes that will run after the boot
     */
    public static function for(array $configuredSteps, BootOptions $options, ?string $serverLabel = null, array $devProcesses = []): self
    {
        $steps = [];
        $stage = null;

        foreach (array_values($configuredSteps) as $index => $entry) {
            $parsed = StepEntry::parse($entry);
            [$class, $parameters] = [$parsed->class, $parsed->parameters];

            // Steps without a #[Stage] inherit the stage they are slotted
            // into; leading unknowns get their own honest "Custom steps".
            $stage = self::stageFor($class) ?? $stage ?? BootStage::Custom;

            $steps[] = new StepDescriptor(
                $index,
                $entry,
                $class,
                $parameters,
                $stage,
                self::label($class, $parameters, $options),
            );
        }

        return new self($steps, $options, $serverLabel, $devProcesses);
    }

    public function count(): int
    {
        return \count($this->steps);
    }

    /**
     * The merged, options-filtered plan lines, in configured order.
     *
     * @return list<string>
     */
    public function summary(): array
    {
        $present = [];

        foreach ($this->steps as $step) {
            $present[$step->class] = true;
        }

        $lines = [];
        $emitted = [];

        foreach ($this->steps as $step) {
            $declared = self::groupFor($step->class);
            $group = $declared ?? "unknown-{$step->index}";

            if (isset($emitted[$group])) {
                continue;
            }

            $emitted[$group] = true;

            $line = $declared !== null
                ? $this->groupLine($declared, $present, $step)
                : $step->label;

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        // Not a step: the dev processes start after the pipeline, from the
        // registry rather than from the configured list.
        $devLine = $this->devLine();

        return $devLine === null ? $lines : [...$lines, $devLine];
    }

    /**
     * What happens once the boot finishes and the processes take over.
     */
    private function devLine(): ?string
    {
        if ($this->devProcesses === []) {
            return null;
        }

        $list = implode(', ', $this->devProcesses);

        return $this->options->follow
            ? "Run the dev processes in this terminal: {$list}"
            : "Run the dev processes in the background: {$list}";
    }

    /**
     * The #[Stage] a step class declares, or null — reflection instead of a
     * hand-maintained class-string map, so third-party steps can join a
     * stage. class_exists() guards reflection on typo'd config entries,
     * which must stay a pipeline-time error, not a summary-time one.
     */
    private static function stageFor(string $class): ?BootStage
    {
        if (! class_exists($class)) {
            return null;
        }

        $attributes = (new ReflectionClass($class))->getAttributes(Stage::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->stage;
    }

    /**
     * The #[Group] a step class declares, or null. Steps sharing a group
     * merge into one summary line at the group's first occurrence.
     */
    private static function groupFor(string $class): ?string
    {
        if (! class_exists($class)) {
            return null;
        }

        $attributes = (new ReflectionClass($class))->getAttributes(Group::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->name;
    }

    /**
     * @param  array<string, true>  $present
     */
    private function groupLine(string $group, array $present, StepDescriptor $step): ?string
    {
        $options = $this->options;

        return match ($group) {
            'prepare' => $this->prepareLine($present),
            'tools' => 'Check required tools are installed',
            'server' => $this->serverLabel !== null
                ? "Start the {$this->serverLabel} development server"
                : 'Start the development server',
            'dependencies' => $this->dependenciesLine($present),
            'database' => 'Prepare the database and verify the connection',
            'deploy-tasks' => "Run the project's configured commands",
            'migrations' => $this->migrationsLine(),
            'cache' => 'Cache the framework files',
            'finalize' => 'Finalize the application',
            'assets' => $options->withAssets ? 'Build frontend assets' : null,
            'announce' => 'Announce the application URL',
            // A third-party group: its first step's label speaks for it.
            default => $step->label,
        };
    }

    /**
     * @param  array<string, true>  $present
     */
    private function prepareLine(array $present): string
    {
        $parts = collect([
            isset($present[EnsureEnvFile::class]) ? '.env file' : null,
            isset($present[EnsureLocalEnvironment::class]) ? 'local environment' : null,
            isset($present[GenerateAppKey::class]) ? 'application key' : null,
        ])->filter()->implode(', ');

        return "Prepare the project ({$parts})";
    }

    /**
     * @param  array<string, true>  $present
     */
    private function dependenciesLine(array $present): ?string
    {
        $kinds = collect([
            isset($present[InstallComposerDependencies::class]) ? 'Composer' : null,
            isset($present[InstallFrontendDependencies::class]) && $this->options->withAssets ? 'frontend' : null,
        ])->filter();

        if ($kinds->isEmpty()) {
            return null;
        }

        $verb = $this->options->update ? 'Update' : 'Install';
        $list = $kinds->implode(' and ');

        return "{$verb} {$list} dependencies";
    }

    private function migrationsLine(): ?string
    {
        if (! $this->options->migrate) {
            return null;
        }

        $line = $this->options->fresh
            ? 'Drop all tables and re-run every migration (asks first)'
            : 'Run pending migrations';

        return $this->options->seed ? "{$line} and seed the database" : $line;
    }

    /**
     * What the progress bar calls a step, asked of the step itself: a
     * DescribesProgress step works it out from the run's options, anything
     * else carries a Label attribute, and a step with neither — a
     * third-party one, usually — is named after its class.
     *
     * @param  list<string>  $parameters
     */
    private static function label(string $class, array $parameters, BootOptions $options): string
    {
        if (! class_exists($class)) {
            return self::fallbackLabel($class, $parameters);
        }

        if (is_a($class, DescribesProgress::class, allow_string: true)) {
            return $class::progressLabel($options, $parameters);
        }

        $attributes = (new ReflectionClass($class))->getAttributes(Label::class);

        return $attributes === []
            ? self::fallbackLabel($class, $parameters)
            : $attributes[0]->newInstance()->text;
    }

    /**
     * @param  list<string>  $parameters
     */
    private static function fallbackLabel(string $class, array $parameters): string
    {
        $label = Str::headline(class_basename($class));

        if ($parameters === []) {
            return $label;
        }

        $list = implode(', ', $parameters);

        return "{$label} ({$list})";
    }
}
