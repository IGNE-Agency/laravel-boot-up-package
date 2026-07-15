<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Database\Steps;

use Closure;
use Igne\LaravelBootstrap\Database\DatabaseConfig;
use Igne\LaravelBootstrap\Database\PendingMigrations;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\ShellCommand;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\Step;
use Igne\LaravelBootstrap\Servers\CommandRewriter;
use Illuminate\Support\Str;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

/**
 * Migrates only when migrations are actually pending. The pending check and
 * the migrate itself run inside the container under Sail (the host cannot
 * reach the container's database); host-side otherwise via the Migrator.
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

            return $next($context);
        }

        if (! $this->config->migrationsAuto) {
            note('Automatic migrations are disabled in configuration — skipping.');

            return $next($context);
        }

        $migrated = $context->server?->key() === 'sail'
            ? $this->migrateThroughSail($context)
            : $this->migrateFromHost();

        if ($migrated && $context->options->seed) {
            info('Seeding database...');

            $this->runner->run($this->rewriter->rewrite(
                ShellCommand::make('php artisan db:seed'),
                $context->server?->commandRewrites(),
            ));
        }

        return $next($context);
    }

    private function migrateThroughSail(ServeContext $context): bool
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
