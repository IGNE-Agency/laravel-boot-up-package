<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Concerns\RunsThroughServer;
use Igne\LaravelBootUp\Config\DatabaseConfig;
use Igne\LaravelBootUp\Contracts\DescribesProgress;
use Igne\LaravelBootUp\Contracts\ProvidesDatabase;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Database\PendingMigrations;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Illuminate\Support\Str;

/**
 * Migrates only when migrations are actually pending. When the host cannot
 * reach the database (e.g. it lives inside Sail's containers) the pending
 * check and the migrate run through the server's command rewrites;
 * host-side otherwise via the Migrator.
 */
#[Stage(ServeStage::Database)]
#[Group('migrations')]
final class RunPendingMigrations implements DescribesProgress, Step
{
    use RunsThroughServer;

    public function __construct(
        private readonly DatabaseConfig $config,
        private readonly PendingMigrations $pendingMigrations,
        private readonly ProcessRunner $runner,
        private readonly CommandRewriter $rewriter,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if (! $context->options->migrate) {
            terminal()->note('Migrations skipped (--no-migrate).');

            if ($context->options->fresh) {
                terminal()->warning('--fresh ignored: --no-migrate wins as the least destructive option.');
            }
        } elseif (! $this->config->migrationsAuto) {
            terminal()->note('Automatic migrations are disabled in configuration — skipping.');
        } elseif ($context->options->fresh && $this->confirmFresh()) {
            // migrate:fresh carries --seed itself, so the shared seed
            // path below must not run a second time.
            $this->migrateFresh($context);

            return $next($context);
        } elseif ($context->server instanceof ProvidesDatabase && ! $context->server->databaseReachableFromHost()) {
            $this->migrateThroughServer($context);
        } else {
            $this->migrateFromHost();
        }

        // Deliberately outside every branch above: --seed also seeds when
        // migrations were skipped or nothing was pending.
        $this->seedIfRequested($context);

        return $next($context);
    }

    /**
     * A declined confirm degrades to the normal pending-migrations flow
     * instead of aborting: the boot should still finish.
     */
    private function confirmFresh(): bool
    {
        if (terminal()->confirm('--fresh drops ALL tables and re-runs every migration. Continue?', default: false)) {
            return true;
        }

        terminal()->note('Fresh migration declined — running pending migrations instead.');

        return false;
    }

    private function migrateFresh(ServeContext $context): void
    {
        terminal()->info('Dropping all tables and re-running every migration...');

        $command = ['php', 'artisan', 'migrate:fresh', '--force'];

        if ($context->options->seed) {
            $command[] = '--seed';
        }

        $this->runThroughServer($context, CommandLine::make($command));
    }

    private function seedIfRequested(ServeContext $context): void
    {
        if (! $context->options->seed) {
            return;
        }

        terminal()->info('Seeding database...');

        $this->runThroughServer($context, CommandLine::make('php artisan db:seed'));
    }

    private function migrateThroughServer(ServeContext $context): bool
    {
        $status = $this->runSilentlyThroughServer($context, CommandLine::make('php artisan migrate:status --pending'));

        $output = trim($status->output());

        if ($output === '' || str_contains($output, 'No pending migrations')) {
            terminal()->success('Database is up to date.');

            return false;
        }

        terminal()->info('Running pending migrations...');

        $this->runThroughServer($context, CommandLine::make('php artisan migrate --force'));

        return true;
    }

    private function migrateFromHost(): bool
    {
        $count = $this->pendingMigrations->count();

        if ($count === 0) {
            terminal()->success('Database is up to date.');

            return false;
        }

        terminal()->info("Running {$count} pending ".Str::plural('migration', $count).'...');

        $this->runner->run(CommandLine::make('php artisan migrate --force'));

        return true;
    }

    public static function progressLabel(ServeOptions $options, array $parameters): string
    {
        return $options->fresh && $options->migrate
            ? 'Rebuilding the database from scratch'
            : 'Running pending migrations';
    }
}
