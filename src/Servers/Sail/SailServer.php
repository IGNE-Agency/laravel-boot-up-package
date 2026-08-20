<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Sail;

use Igne\LaravelBootUp\Concerns\ReadsProcessFailureOutput;
use Igne\LaravelBootUp\Config\SailConfig;
use Igne\LaravelBootUp\Contracts\HasResidualState;
use Igne\LaravelBootUp\Contracts\ProvidesDatabase;
use Igne\LaravelBootUp\Contracts\ProvidesDevProcess;
use Igne\LaravelBootUp\Contracts\RequiresTools;
use Igne\LaravelBootUp\Contracts\RewritesCommands;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\CommandRewrites;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Exceptions\ServerException;
use Igne\LaravelBootUp\Services\Poller;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Process\Exceptions\ProcessFailedException;

final class SailServer implements HasResidualState, ProvidesDatabase, ProvidesDevProcess, RequiresTools, RewritesCommands, Server
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
        return [Tool::DOCKER];
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

    public function start(ServeContext $context): void
    {
        $this->docker->ensureRunning();

        if (! $this->sail->isConfigured()) {
            terminal()->info('Scaffolding Sail configuration...');
            $this->sail->scaffold();
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
     * A failed `sail up` has two recoverable shapes: an unreachable registry
     * (environmental — explain instead of dumping raw compose errors) and an
     * application image an earlier failed boot never built (compose tries to
     * pull `sail-x.y/app` from a registry that does not have it; a --build
     * retry is the actual fix).
     */
    private function recoverFromFailedUp(ProcessFailedException $exception): void
    {
        $output = $this->outputOf($exception);

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
     * The containers are already up by the time the dev processes start, so
     * the [server] process follows their logs rather than starting anything.
     */
    public function devProcess(ServeContext $context): ?CommandLine
    {
        return $context->options->follow
            ? CommandLine::make(['./vendor/bin/sail', 'logs', '--follow'])->withTimeout(null)
            : null;
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
