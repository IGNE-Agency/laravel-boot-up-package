<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database\Steps;

use Closure;
use Igne\LaravelBootUp\Contracts\ProvidesDatabase;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ShellCommand;
use Igne\LaravelBootUp\Exceptions\DatabaseException;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Proves the database is reachable before anything migrates. When the host
 * cannot reach the database (e.g. it lives inside Sail's containers) the
 * check runs through the server's command rewrites; otherwise a host-side
 * PDO connection is enough.
 */
final class VerifyDatabaseConnection implements Step
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly CommandRewriter $rewriter,
        private readonly Repository $laravelConfig,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if ($context->server instanceof ProvidesDatabase && ! $context->server->databaseReachableFromHost()) {
            $this->verifyThroughServer($context);
        } else {
            $this->verifyFromHost();
        }

        terminal()->success('Database connection verified.');

        return $next($context);
    }

    private function verifyThroughServer(ServeContext $context): void
    {
        $result = $this->runner->runSilently($this->rewriter->rewrite(
            ShellCommand::make('php artisan migrate:status'),
            $context->commandRewrites(),
        ));

        if (! $result->successful()) {
            throw DatabaseException::connectionFailed($context->server?->key() ?? 'server', trim($result->errorOutput()));
        }
    }

    private function verifyFromHost(): void
    {
        $default = (string) $this->laravelConfig->get('database.default');
        $driver = (string) $this->laravelConfig->get("database.connections.{$default}.driver", $default);

        try {
            DB::connection()->getPdo();
        } catch (Throwable $exception) {
            throw DatabaseException::connectionFailed($driver, $exception->getMessage());
        }
    }
}
