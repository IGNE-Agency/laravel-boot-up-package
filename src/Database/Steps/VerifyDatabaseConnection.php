<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Database\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Label;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Concerns\RunsThroughServer;
use Igne\LaravelBootUp\Config\DatabaseConfig;
use Igne\LaravelBootUp\Console\DbPingCommand;
use Igne\LaravelBootUp\Contracts\ProvidesDatabase;
use Igne\LaravelBootUp\Contracts\ResetsDatabaseStorage;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Database\DatabaseFailureDetector;
use Igne\LaravelBootUp\Database\DatabaseProbe;
use Igne\LaravelBootUp\Enums\BootStage;
use Igne\LaravelBootUp\Enums\DatabaseFailure;
use Igne\LaravelBootUp\Exceptions\DatabaseException;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Igne\LaravelBootUp\Services\Poller;
use Illuminate\Contracts\Process\ProcessResult;

/**
 * Proves the database is reachable before anything migrates. When the host
 * cannot reach the database (e.g. it lives inside Sail's containers) the
 * check runs through the server's command rewrites as app:db-ping;
 * otherwise the same DatabaseProbe runs host-side, in process.
 *
 * Connecting is the whole question — deliberately nothing about schema.
 * An earlier version probed with `migrate:status`, which exits non-zero on
 * a connected database that has no migrations table yet: precisely the
 * state of a volume this boot just created, so a first boot could never
 * pass its own check.
 *
 * Either way the check polls: a database container initializes itself on
 * its first boot and legitimately refuses connections for a while. Settled
 * verdicts — rejected credentials, a database the server does not have —
 * stop the poll immediately, and when the server's database storage
 * predates this boot they earn an offer to recreate it, because both are
 * baked into that storage and no .env edit can reach them.
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
        private readonly DatabaseProbe $probe,
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
        // failure is expected to clear — but the fresh container needs its
        // own init window, hence a second full poll rather than one retry.
        if ($this->detector->classify($output)->fixableByRecreatingStorage() && $this->offerReset($context)) {
            $result = $this->attemptUntilReady($context);

            if ($result->successful()) {
                return;
            }

            $output = $this->combinedOutput($result);
        }

        throw $this->failureFor($server, $output);
    }

    /**
     * Run the check until it succeeds, a terminal failure settles it, or
     * the readiness timeout elapses. A timeout of zero means one attempt —
     * the poller still evaluates the condition once after the deadline.
     */
    private function attemptUntilReady(BootContext $context): ProcessResult
    {
        $last = null;

        $this->poller->until(
            function () use ($context, &$last): bool {
                $last = $this->runSilentlyThroughServer($context, CommandLine::make('php artisan '.DbPingCommand::NAME));

                return $last->successful() || $this->detector->isTerminal($this->combinedOutput($last));
            },
            timeoutSeconds: $this->config->readyTimeoutSeconds,
            intervalMs: $this->pollIntervalMs,
        );

        \assert($last instanceof ProcessResult);

        return $last;
    }

    /**
     * Offer to recreate the server's database storage, when there is one to
     * recreate and it predates this boot — only then can the failure mean
     * "baked into stale storage" rather than a wrong .env value this boot
     * has just applied.
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
            "The database rejected what your .env asks for, but {$server->databaseStorageLabel()} already existed "
            .'before this boot — the database name and credentials it was first created with are baked in, and '
            .'changing .env never updates them.',
        );

        if (! terminal()->confirm(
            label: 'Recreate it with the values currently in .env? All data in it will be lost.',
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
        $failure = null;

        $this->poller->until(
            function () use (&$failure): bool {
                $failure = $this->probe->attempt();

                // A terminal failure settles the poll too: true here means
                // "settled", and $failure carries the verdict.
                return $failure === null || $this->detector->isTerminal($failure);
            },
            timeoutSeconds: $this->config->readyTimeoutSeconds,
            intervalMs: $this->pollIntervalMs,
        );

        if ($failure === null) {
            return;
        }

        throw $this->failureFor($this->probe->driver(), $failure);
    }

    /**
     * The verdict, phrased for whoever has to fix it: credentials, a
     * missing database, or a server that never answered in time.
     */
    private function failureFor(string $server, string $output): DatabaseException
    {
        return match ($this->detector->classify($output)) {
            DatabaseFailure::AccessDenied => DatabaseException::accessDenied($server, trim($output)),
            DatabaseFailure::UnknownDatabase => DatabaseException::unknownDatabase(
                $server,
                $this->probe->databaseName(),
                trim($output),
            ),
            DatabaseFailure::Transient => DatabaseException::connectionFailed($server, $this->reasonFrom($output)),
        };
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
