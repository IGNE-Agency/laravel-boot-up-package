<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Data\StepDescriptor;
use Igne\LaravelBootUp\Database\Steps\EnsureDatabaseCredentials;
use Igne\LaravelBootUp\Database\Steps\EnsureDatabaseExists;
use Igne\LaravelBootUp\Database\Steps\RunPendingMigrations;
use Igne\LaravelBootUp\Database\Steps\VerifyDatabaseConnection;
use Igne\LaravelBootUp\Deploy\Steps\CacheFrameworkFiles;
use Igne\LaravelBootUp\Deploy\Steps\FinalizeApplication;
use Igne\LaravelBootUp\Deploy\Steps\InstallComposerDependencies;
use Igne\LaravelBootUp\Deploy\Steps\RunDeployTasks;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Environment\Steps\EnsureEnvFile;
use Igne\LaravelBootUp\Environment\Steps\EnsureLocalEnvironment;
use Igne\LaravelBootUp\Environment\Steps\GenerateAppKey;
use Igne\LaravelBootUp\Frontend\Steps\BuildOrWatchAssets;
use Igne\LaravelBootUp\Frontend\Steps\InstallFrontendDependencies;
use Igne\LaravelBootUp\Queue\Steps\StartQueueWorker;
use Igne\LaravelBootUp\Serve\Steps\AnnounceApplication;
use Igne\LaravelBootUp\Servers\Steps\StartServer;
use Igne\LaravelBootUp\Tools\Steps\EnsureToolsReady;
use Igne\LaravelBootUp\Workers\Steps\StartHorizon;
use Igne\LaravelBootUp\Workers\Steps\StartReverb;
use Igne\LaravelBootUp\Workers\Steps\StartScheduler;
use Illuminate\Support\Str;

/**
 * What the configured serve pipeline is about to do: each entry parsed and
 * assigned a stage plus a progress label, and a concise options-aware
 * summary for the "What app:serve will do" block. Only ServeOptions gate
 * summary lines — config/context skip logic stays inside the steps.
 */
final readonly class StepSequence
{
    private const STAGES = [
        EnsureEnvFile::class => ServeStage::Prepare,
        EnsureLocalEnvironment::class => ServeStage::Prepare,
        GenerateAppKey::class => ServeStage::Prepare,
        EnsureToolsReady::class => ServeStage::Tools,
        StartServer::class => ServeStage::Server,
        InstallComposerDependencies::class => ServeStage::Install,
        InstallFrontendDependencies::class => ServeStage::Install,
        EnsureDatabaseCredentials::class => ServeStage::Database,
        EnsureDatabaseExists::class => ServeStage::Database,
        VerifyDatabaseConnection::class => ServeStage::Database,
        RunDeployTasks::class => ServeStage::Database,
        RunPendingMigrations::class => ServeStage::Database,
        CacheFrameworkFiles::class => ServeStage::Cache,
        FinalizeApplication::class => ServeStage::Finalize,
        StartQueueWorker::class => ServeStage::Services,
        StartHorizon::class => ServeStage::Services,
        StartReverb::class => ServeStage::Services,
        StartScheduler::class => ServeStage::Services,
        BuildOrWatchAssets::class => ServeStage::Assets,
        AnnounceApplication::class => ServeStage::Announce,
    ];

    /**
     * Known steps merge into one summary line per group, emitted at the
     * group's first occurrence in the configured order.
     */
    private const GROUPS = [
        EnsureEnvFile::class => 'prepare',
        EnsureLocalEnvironment::class => 'prepare',
        GenerateAppKey::class => 'prepare',
        EnsureToolsReady::class => 'tools',
        StartServer::class => 'server',
        InstallComposerDependencies::class => 'dependencies',
        InstallFrontendDependencies::class => 'dependencies',
        EnsureDatabaseCredentials::class => 'database',
        EnsureDatabaseExists::class => 'database',
        VerifyDatabaseConnection::class => 'database',
        RunDeployTasks::class => 'deploy-tasks',
        RunPendingMigrations::class => 'migrations',
        CacheFrameworkFiles::class => 'cache',
        FinalizeApplication::class => 'finalize',
        StartQueueWorker::class => 'workers',
        StartHorizon::class => 'workers',
        StartReverb::class => 'workers',
        StartScheduler::class => 'workers',
        BuildOrWatchAssets::class => 'assets',
        AnnounceApplication::class => 'announce',
    ];

    /**
     * @param  list<StepDescriptor>  $steps
     */
    private function __construct(
        public array $steps,
        private ServeOptions $options,
        private ?string $serverLabel,
    ) {}

    /**
     * @param  list<string>  $configuredSteps
     */
    public static function for(array $configuredSteps, ServeOptions $options, ?string $serverLabel = null): self
    {
        $steps = [];
        $stage = null;

        foreach (array_values($configuredSteps) as $index => $entry) {
            [$class, $parameters] = self::parse($entry);

            // Unknown classes inherit the stage they are slotted into;
            // leading unknowns get their own honest "Custom steps" stage.
            $stage = self::STAGES[$class] ?? $stage ?? ServeStage::Custom;

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
        $present = [];

        foreach ($this->steps as $step) {
            $present[$step->class] = true;
        }

        $lines = [];
        $emitted = [];

        foreach ($this->steps as $step) {
            $group = self::GROUPS[$step->class] ?? "unknown-{$step->index}";

            if (isset($emitted[$group])) {
                continue;
            }

            $emitted[$group] = true;

            $line = isset(self::GROUPS[$step->class])
                ? $this->groupLine($group, $present)
                : $step->label;

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function parse(string $entry): array
    {
        // Mirrors Illuminate\Pipeline\Pipeline::parsePipeString(), which is
        // protected upstream: "Class:a,b" => [Class, ['a', 'b']].
        [$class, $parameters] = array_pad(explode(':', $entry, 2), 2, null);

        return [$class, $parameters === null ? [] : explode(',', $parameters)];
    }

    /**
     * @param  array<string, true>  $present
     */
    private function groupLine(string $group, array $present): ?string
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
            'workers' => $this->workersLine($present),
            'assets' => $options->withAssets ? 'Build or watch frontend assets' : null,
            'announce' => 'Announce the application URL',
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
     * @param  array<string, true>  $present
     */
    private function workersLine(array $present): ?string
    {
        $services = collect([
            isset($present[StartQueueWorker::class]) && $this->options->withQueue ? 'queue worker' : null,
            isset($present[StartHorizon::class]) ? 'Horizon' : null,
            isset($present[StartReverb::class]) ? 'Reverb' : null,
            isset($present[StartScheduler::class]) ? 'scheduler' : null,
        ])->filter();

        if ($services->isEmpty()) {
            return null;
        }

        $list = $services->implode(', ');

        return "Start long-running services when enabled: {$list}";
    }

    /**
     * @param  list<string>  $parameters
     */
    private static function label(string $class, array $parameters, ServeOptions $options): string
    {
        return match ($class) {
            EnsureEnvFile::class => 'Checking the .env file',
            EnsureLocalEnvironment::class => 'Checking the local environment',
            GenerateAppKey::class => 'Checking the application key',
            EnsureToolsReady::class => 'Checking required tools',
            StartServer::class => 'Starting the development server',
            InstallComposerDependencies::class => $options->update ? 'Updating Composer dependencies' : 'Installing Composer dependencies',
            InstallFrontendDependencies::class => $options->update ? 'Updating frontend dependencies' : 'Installing frontend dependencies',
            EnsureDatabaseCredentials::class => 'Checking database credentials',
            EnsureDatabaseExists::class => 'Ensuring the database exists',
            VerifyDatabaseConnection::class => 'Verifying the database connection',
            RunDeployTasks::class => ($parameters[0] ?? 'before') === 'after'
                ? 'Running project commands (after migrations)'
                : 'Running project commands (before migrations)',
            RunPendingMigrations::class => $options->fresh && $options->migrate
                ? 'Rebuilding the database from scratch'
                : 'Running pending migrations',
            CacheFrameworkFiles::class => 'Caching framework files',
            FinalizeApplication::class => 'Finalizing the application',
            StartQueueWorker::class => 'Starting the queue worker',
            StartHorizon::class => 'Starting Horizon',
            StartReverb::class => 'Starting Reverb',
            StartScheduler::class => 'Starting the scheduler',
            BuildOrWatchAssets::class => 'Building or watching assets',
            AnnounceApplication::class => 'Announcing the application',
            default => self::fallbackLabel($class, $parameters),
        };
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
