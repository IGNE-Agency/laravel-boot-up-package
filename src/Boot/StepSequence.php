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
 * summary for the "What app:up will do" block. Only BootOptions gate
 * summary lines — config/context skip logic stays inside the steps.
 */
final readonly class StepSequence
{
    /**
     * @param  list<StepDescriptor>  $steps
     */
    private function __construct(
        public array $steps,
        private BootOptions $options,
        private ?string $serverLabel,
    ) {}

    /**
     * @param  list<string>  $configuredSteps
     */
    public static function for(array $configuredSteps, BootOptions $options, ?string $serverLabel = null): self
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

        return new self($steps, $options, $serverLabel);
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
        // The first occurrence of a #[Group] speaks for the whole group;
        // ungrouped steps get a per-index key so they never merge.
        return collect($this->steps)
            ->unique(fn (StepDescriptor $step): string => self::groupFor($step->class) ?? "unknown-{$step->index}", strict: true)
            ->map(function (StepDescriptor $step): ?string {
                $declared = self::groupFor($step->class);

                return $declared !== null ? $this->groupLine($declared, $step) : $step->label;
            })
            ->filter(fn (?string $line): bool => $line !== null)
            ->values()
            ->all();
    }

    /**
     * Whether the pipeline contains the given step class, so the summary
     * lines can name only the parts that will actually run.
     */
    private function includes(string $class): bool
    {
        return collect($this->steps)->contains(fn (StepDescriptor $step): bool => $step->class === $class);
    }

    /**
     * The #[Stage] a step class declares, or null — reflection instead of a
     * hand-maintained class-string map, so third-party steps can join a
     * stage.
     */
    private static function stageFor(string $class): ?BootStage
    {
        return self::attribute($class, Stage::class)?->stage;
    }

    /**
     * The #[Group] a step class declares, or null. Steps sharing a group
     * merge into one summary line at the group's first occurrence.
     */
    private static function groupFor(string $class): ?string
    {
        return self::attribute($class, Group::class)?->name;
    }

    /**
     * The first instance of the given attribute on the class, or null when
     * there is none. class_exists() guards reflection on typo'd config
     * entries, which must stay a pipeline-time error, not a summary-time one.
     *
     * @template TAttribute of object
     *
     * @param  class-string<TAttribute>  $attribute
     * @return TAttribute|null
     */
    private static function attribute(string $class, string $attribute): ?object
    {
        if (! class_exists($class)) {
            return null;
        }

        $attributes = (new ReflectionClass($class))->getAttributes($attribute);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    private function groupLine(string $group, StepDescriptor $step): ?string
    {
        $options = $this->options;

        return match ($group) {
            'prepare' => $this->prepareLine(),
            'tools' => 'Check required tools are installed',
            'server' => $this->serverLabel !== null
                ? "Start the {$this->serverLabel} development server"
                : 'Start the development server',
            'dependencies' => $this->dependenciesLine(),
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

    private function prepareLine(): string
    {
        $parts = collect([
            $this->includes(EnsureEnvFile::class) ? '.env file' : null,
            $this->includes(EnsureLocalEnvironment::class) ? 'local environment' : null,
            $this->includes(GenerateAppKey::class) ? 'application key' : null,
        ])->filter()->implode(', ');

        return "Prepare the project ({$parts})";
    }

    private function dependenciesLine(): ?string
    {
        $kinds = collect([
            $this->includes(InstallComposerDependencies::class) ? 'Composer' : null,
            $this->includes(InstallFrontendDependencies::class) && $this->options->withAssets ? 'frontend' : null,
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

        return self::attribute($class, Label::class)->text ?? self::fallbackLabel($class, $parameters);
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
