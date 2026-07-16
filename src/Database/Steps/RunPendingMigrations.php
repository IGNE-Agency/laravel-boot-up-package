<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database\Steps;

use Closure;
use Igne\LaravelBootUp\Database\DatabaseConfig;
use Igne\LaravelBootUp\Database\PendingMigrations;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

/**
 * Migrates only when migrations are actually pending. When the host cannot
 * reach the database (e.g. it lives inside Sail's containers) the pending
 * check and the migrate run through the server's command rewrites;
 * host-side otherwise via the Migrator.
 */
final class RunPendingMigrations implements Step
{
    public function __construct(
        private readonly DatabaseConfig $config,
        private readonly PendingMigrations $pendingMigrations,
        private readonly ProcessRunner $runner,
        private readonly CommandRewriter $rewriter,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if (! $context->options->migrate) {
            note('Migrations skipped (--no-migrate).');

            if ($context->options->fresh) {
                warning('--fresh ignored: --no-migrate wins as the least destructive option.');
            }
        } elseif (! $this->config->migrationsAuto) {
            note('Automatic migrations are disabled in configuration — skipping.');
        } elseif ($context->options->fresh && $this->confirmFresh()) {
            // migrate:fresh carries --seed itself, so the shared seed
            // path below must not run a second time.
            $this->migrateFresh($context);

            return $next($context);
        } elseif ($context->server !== null && ! $context->server->databaseReachableFromHost()) {
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
        if (confirm('--fresh drops ALL tables and re-runs every migration. Continue?', default: false)) {
            return true;
        }

        note('Fresh migration declined — running pending migrations instead.');

        return false;
    }

    private function migrateFresh(ServeContext $context): void
    {
        info('Dropping all tables and re-running every migration...');

        $command = ['php', 'artisan', 'migrate:fresh', '--force'];

        if ($context->options->seed) {
            $command[] = '--seed';
        }

        $this->runner->run($this->rewriter->rewrite(
            ShellCommand::make($command),
            $context->server?->commandRewrites(),
        ));
    }

    private function seedIfRequested(ServeContext $context): void
    {
        if (! $context->options->seed) {
            return;
        }

        info('Seeding database...');

        $this->runner->run($this->rewriter->rewrite(
            ShellCommand::make('php artisan db:seed'),
            $context->server?->commandRewrites(),
        ));
    }

    private function migrateThroughServer(ServeContext $context): bool
    {
        $status = $this->runner->runSilently($this->rewriter->rewrite(
            ShellCommand::make('php artisan migrate:status --pending'),
            $context->server?->commandRewrites(),
        ));

        $output = trim($status->output());

        if ($output === '' || str_contains($output, 'No pending migrations')) {
            info('Database is up to date.');

            return false;
        }

        info('Running pending migrations...');

        $this->runner->run($this->rewriter->rewrite(
            ShellCommand::make('php artisan migrate --force'),
            $context->server?->commandRewrites(),
        ));

        return true;
    }

    private function migrateFromHost(): bool
    {
        $count = $this->pendingMigrations->count();

        if ($count === 0) {
            info('Database is up to date.');

            return false;
        }

        info("Running {$count} pending ".Str::plural('migration', $count).'...');

        $this->runner->run(ShellCommand::make('php artisan migrate --force'));

        return true;
    }
}
