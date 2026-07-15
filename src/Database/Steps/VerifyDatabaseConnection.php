<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database\Steps;

use Closure;
use Igne\LaravelBootUp\Database\DatabaseException;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\info;

use Throwable;

/**
 * Proves the database is reachable before anything migrates. Under Sail the
 * check runs inside the container (the host cannot reach `mysql`); otherwise
 * a host-side PDO connection is enough.
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
        if ($context->server?->key() === 'sail') {
            $this->verifyThroughSail($context);
        } else {
            $this->verifyFromHost();
        }

        info('Database connection verified.');

        return $next($context);
    }

    private function verifyThroughSail(ServeContext $context): void
    {
        $result = $this->runner->runSilently($this->rewriter->rewrite(
            ShellCommand::make('php artisan migrate:status'),
            $context->server?->commandRewrites(),
        ));

        if (! $result->successful()) {
            throw DatabaseException::connectionFailed('sail', trim($result->errorOutput()));
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
