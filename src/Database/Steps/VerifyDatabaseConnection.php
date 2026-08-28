<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Label;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Concerns\RunsThroughServer;
use Igne\LaravelBootUp\Contracts\ProvidesDatabase;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Enums\BootStage;
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
#[Stage(BootStage::Database)]
#[Group('database')]
#[Label('Verifying the database connection')]
final class VerifyDatabaseConnection implements Step
{
    use RunsThroughServer;

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly CommandRewriter $rewriter,
        private readonly Repository $laravelConfig,
    ) {}

    public function handle(BootContext $context, Closure $next): mixed
    {
        if ($context->server instanceof ProvidesDatabase && ! $context->server->databaseReachableFromHost()) {
            $this->verifyThroughServer($context);
        } else {
            $this->verifyFromHost();
        }

        terminal()->success('Database connection verified.');

        return $next($context);
    }

    private function verifyThroughServer(BootContext $context): void
    {
        $result = $this->runSilentlyThroughServer($context, CommandLine::make('php artisan migrate:status'));

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
