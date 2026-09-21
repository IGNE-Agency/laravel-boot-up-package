<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Label;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Concerns\RunsThroughServer;
use Igne\LaravelBootUp\Config\DatabaseConfig;
use Igne\LaravelBootUp\Contracts\ProvidesDatabase;
use Igne\LaravelBootUp\Contracts\ResetsDatabaseStorage;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Database\DatabaseFailureDetector;
use Igne\LaravelBootUp\Enums\BootStage;
use Igne\LaravelBootUp\Exceptions\DatabaseException;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Igne\LaravelBootUp\Services\Poller;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Proves the database is reachable before anything migrates. When the host
 * cannot reach the database (e.g. it lives inside Sail's containers) the
 * check runs through the server's command rewrites; otherwise a host-side
 * PDO connection is enough.
 *
 * Either way the check polls: a database container initializes itself on
 * its first boot and legitimately refuses connections for a while. Rejected
 * credentials are the one failure waiting cannot fix, so they stop the poll
 * immediately — and when the server's database storage predates this boot,
 * they earn an offer to recreate it, because baked-in credentials are the
 * usual culprit and no .env edit can reach them.
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
        private readonly Poller $poller,
        private readonly DatabaseConfig $config,
        private readonly DatabaseFailureDetector $detector,
        private readonly int $pollIntervalMs = 2000,
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
        $result = $this->attemptUntilReady($context);

        if ($result->successful()) {
            return;
        }

        $server = $context->server?->key() ?? 'server';
        $output = $this->combinedOutput($result);

        // A recreated volume re-initializes from the current .env, so the
        // rejection is expected to clear — but the fresh container needs its
        // own init window, hence a second full poll rather than one retry.
        if ($this->detector->isAccessDenied($output) && $this->offerReset($context)) {
            $result = $this->attemptUntilReady($context);

            if ($result->successful()) {
                return;
            }

            $output = $this->combinedOutput($result);
        }

        throw $this->detector->isAccessDenied($output)
            ? DatabaseException::accessDenied($server, trim($output))
            : DatabaseException::connectionFailed($server, $this->reasonFrom($output));
    }

    /**
     * Run the check until it succeeds, the credentials are rejected, or the
     * readiness timeout elapses. A timeout of zero means one attempt — the
     * poller still evaluates the condition once after the deadline.
     */
    private function attemptUntilReady(BootContext $context): ProcessResult
    {
        $last = null;

        $this->poller->until(
            function () use ($context, &$last): bool {
                $last = $this->runSilentlyThroughServer($context, CommandLine::make('php artisan migrate:status'));

                return $last->successful() || $this->detector->isAccessDenied($this->combinedOutput($last));
            },
            timeoutSeconds: $this->config->readyTimeoutSeconds,
            intervalMs: $this->pollIntervalMs,
        );

        \assert($last instanceof ProcessResult);

        return $last;
    }

    /**
     * Offer to recreate the server's database storage, when there is one to
     * recreate and it predates this boot — only then can the rejection mean
     * "credentials baked into stale storage" rather than a wrong .env value.
     *
     * Destructive, so the auto-accept flags are ignored on purpose: an
     * unattended run gets the default answer, which is No.
     */
    private function offerReset(BootContext $context): bool
    {
        $server = $context->server;

        if (! $server instanceof ResetsDatabaseStorage
            || ! $server->databaseStorageResettable()
            || ! $server->databaseStorageExistedBeforeBoot()) {
            return false;
        }

        terminal()->warning(
            "The database rejected the DB_USERNAME / DB_PASSWORD in your .env, but {$server->databaseStorageLabel()} "
            .'already existed before this boot — the credentials it was first created with are baked in, and '
            .'changing .env never updates them.',
        );

        if (! terminal()->confirm(
            label: 'Recreate it with the credentials currently in .env? All data in it will be lost.',
            default: false,
        )) {
            terminal()->orderedList('To fix this yourself', $server->databaseStorageResetInstructions());

            return false;
        }

        $server->resetDatabaseStorage();

        return true;
    }

    private function verifyFromHost(): void
    {
        $default = (string) $this->laravelConfig->get('database.default');
        $driver = (string) $this->laravelConfig->get("database.connections.{$default}.driver", $default);

        $failure = null;

        $this->poller->until(
            function () use ($default, &$failure): bool {
                try {
                    // Purged so a retry opens a fresh PDO instead of reusing
                    // the connection whose handshake just failed.
                    DB::purge($default);
                    DB::connection()->getPdo();

                    $failure = null;

                    return true;
                } catch (Throwable $exception) {
                    $failure = $exception;

                    // Rejected credentials stop the poll: true here means
                    // "settled", and $failure carries the verdict.
                    return $this->detector->isAccessDenied($exception->getMessage());
                }
            },
            timeoutSeconds: $this->config->readyTimeoutSeconds,
            intervalMs: $this->pollIntervalMs,
        );

        if ($failure === null) {
            return;
        }

        throw $this->detector->isAccessDenied($failure->getMessage())
            ? DatabaseException::accessDenied($driver, $failure->getMessage())
            : DatabaseException::connectionFailed($driver, $failure->getMessage());
    }

    /**
     * Both streams: artisan renders connection exceptions to stdout in some
     * setups, compose and PDO write theirs to stderr.
     */
    private function combinedOutput(ProcessResult $result): string
    {
        return trim($result->output()."\n".$result->errorOutput());
    }

    private function reasonFrom(string $output): string
    {
        $trimmed = trim($output);

        return $trimmed === ''
            ? "the database did not accept connections within {$this->config->readyTimeoutSeconds} seconds."
            : $trimmed;
    }
}
