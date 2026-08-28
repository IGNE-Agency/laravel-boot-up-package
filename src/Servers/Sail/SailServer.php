<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Sail;

use Igne\LaravelBootUp\Concerns\ReadsProcessFailureOutput;
use Igne\LaravelBootUp\Config\SailConfig;
use Igne\LaravelBootUp\Contracts\HasResidualState;
use Igne\LaravelBootUp\Contracts\ProvidesDatabase;
use Igne\LaravelBootUp\Contracts\ProvidesDevProcess;
use Igne\LaravelBootUp\Contracts\RequiresTools;
use Igne\LaravelBootUp\Contracts\ReservesPorts;
use Igne\LaravelBootUp\Contracts\RewritesCommands;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\CommandRewrites;
use Igne\LaravelBootUp\Data\PortConflict;
use Igne\LaravelBootUp\Data\ReservedPort;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Environment\EnvRestorePoint;
use Igne\LaravelBootUp\Exceptions\ServerException;
use Igne\LaravelBootUp\Services\Poller;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Process\Exceptions\ProcessFailedException;

final class SailServer implements HasResidualState, ProvidesDatabase, ProvidesDevProcess, RequiresTools, ReservesPorts, RewritesCommands, Server
{
    use ReadsProcessFailureOutput;

    public function __construct(
        private readonly Docker $docker,
        private readonly Sail $sail,
        private readonly SailAliasInstaller $aliasInstaller,
        private readonly Poller $poller,
        private readonly Repository $laravelConfig,
        private readonly EnvFile $envFile,
        private readonly SailUpFailureDetector $detector,
        private readonly SailPorts $ports,
        private readonly EnvRestorePoint $envRestore,
        private readonly SailConfig $config,
    ) {}

    public function key(): string
    {
        return 'sail';
    }

    public function label(): string
    {
        return 'Laravel Sail';
    }

    /**
     * @return list<Tool>
     */
    public function requiredTools(): array
    {
        return [Tool::Docker];
    }

    public function commandRewrites(): CommandRewrites
    {
        return new CommandRewrites(
            replaces: ['php artisan' => 'artisan'],
            prefixes: ['php', 'composer', 'yarn', 'npm', 'bun', 'pnpm', 'artisan', 'node'],
            prefix: './vendor/bin/sail',
        );
    }

    public function databaseReachableFromHost(): bool
    {
        return false;
    }

    /**
     * Reading the compose config goes through sail, which refuses to run
     * without a daemon — so the daemon comes up here rather than in start().
     * ensureRunning() checks before it acts, so start() calling it again
     * costs one `docker info`.
     *
     * @return list<ReservedPort>
     */
    public function reservedPorts(): array
    {
        $this->docker->ensureRunning();

        return $this->ports->published();
    }

    public function start(BootContext $context): void
    {
        $this->docker->ensureRunning();

        if (! $this->sail->isConfigured()) {
            terminal()->info('Scaffolding Sail configuration...');

            // `sail:install` rewrites the .env in place — DB_HOST, DB_USERNAME,
            // DB_PASSWORD, and REDIS_HOST / SCOUT_DRIVER for whatever else it
            // scaffolds. Those values only work while these containers run, so
            // record them for the teardown to put back.
            $this->envRestore->around($this->sail->scaffold(...));
        }

        try {
            $this->sail->up();
        } catch (ProcessFailedException $exception) {
            $this->recoverFromFailedUp($exception);
        }

        $ready = $this->poller->until(
            fn (): bool => $this->sail->hasRunningContainers(),
            timeoutSeconds: $this->config->readyTimeoutSeconds,
            intervalMs: 1000,
        );

        if (! $ready) {
            throw ServerException::startFailed(
                $this->label(),
                "containers did not come up within {$this->config->readyTimeoutSeconds} seconds",
            );
        }

        $this->aliasInstaller->ensure();
    }

    /**
     * A failed `sail up` has three shapes worth explaining: a taken host port,
     * an unreachable registry (environmental — explain instead of dumping raw
     * compose errors) and an application image an earlier failed boot never
     * built (compose tries to pull `sail-x.y/app` from a registry that does not
     * have it; a --build retry is the actual fix).
     *
     * The port check comes first because it is unambiguous, and because a
     * --build retry would only fail the same way.
     */
    private function recoverFromFailedUp(ProcessFailedException $exception): void
    {
        $output = $this->outputOf($exception);

        $conflicts = $this->detector->isPortConflict($output) ? $this->conflictsIn($output) : [];

        // A port conflict whose port compose did not name leaves nothing to
        // explain, so the raw failure is still the most informative thing.
        if ($conflicts !== []) {
            throw ServerException::portsUnavailable($this->label(), $conflicts);
        }

        if ($this->detector->isRegistryUnreachable($output)) {
            throw ServerException::dockerRegistryUnreachable();
        }

        if (! $this->detector->isMissingLocalImage($output)) {
            throw $exception;
        }

        terminal()->warning("Sail's application image has not been built yet (an earlier boot likely failed before it was built) — retrying with `sail up -d --build`...");

        try {
            $this->sail->up(build: true);
        } catch (ProcessFailedException $retry) {
            throw $this->detector->isRegistryUnreachable($this->outputOf($retry))
                ? ServerException::dockerRegistryUnreachable()
                : $retry;
        }
    }

    /**
     * Match the ports compose named against the ones it meant to publish, so
     * the remedy can name the variable that moves each. Ports the compose
     * config no longer explains still get reported, just more vaguely.
     *
     * @return list<PortConflict>
     */
    private function conflictsIn(string $output): array
    {
        $reserved = [];

        foreach ($this->ports->published() as $port) {
            $reserved[$port->port] = $port;
        }

        return array_map(
            fn (int $port): PortConflict => new PortConflict($reserved[$port] ?? new ReservedPort(
                port: $port,
                purpose: 'a container',
                fix: 'publish it on a different host port in your compose file',
            )),
            $this->detector->portsIn($output),
        );
    }

    /**
     * The containers are already up by the time the dev processes start, so
     * the [server] process tails their logs rather than starting anything.
     */
    public function devProcess(BootContext $context): CommandLine
    {
        return CommandLine::make(['./vendor/bin/sail', 'logs', '--follow'])->withTimeout(null);
    }

    public function isRunning(): bool
    {
        return $this->sail->hasRunningContainers();
    }

    public function stop(): void
    {
        $this->sail->down();
    }

    /**
     * Cheap file checks only — Docker may not even be running, and probing
     * it from a teardown path could relaunch the daemon.
     */
    public function hasResidualState(): bool
    {
        return $this->sail->isInstalled() && $this->sail->isConfigured();
    }

    public function residualStateImpact(): string
    {
        return 'A failed `sail up` can leave stopped containers, networks and half-pulled images behind; cleanup runs `./vendor/bin/sail down`.';
    }

    public function cleanUpResidualState(): void
    {
        $this->sail->down();
    }

    /**
     * APP_URL from the .env wins (the loaded config may predate a fresh
     * .env); http://localhost matches Sail's default port-80 binding when
     * neither source has a value.
     */
    public function url(): string
    {
        $url = $this->envFile->valueOr('APP_URL', (string) $this->laravelConfig->get('app.url'));

        return $url !== '' ? $url : 'http://localhost';
    }
}
