<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Sail;

use Igne\LaravelBootUp\Contracts\ProvidesDatabase;
use Igne\LaravelBootUp\Contracts\RequiresTools;
use Igne\LaravelBootUp\Contracts\RewritesCommands;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\CommandRewrites;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Exceptions\ServerException;
use Igne\LaravelBootUp\Services\Poller;
use Illuminate\Contracts\Config\Repository;

final class SailServer implements ProvidesDatabase, RequiresTools, RewritesCommands, Server
{
    public function __construct(
        private readonly Docker $docker,
        private readonly Sail $sail,
        private readonly SailAliasInstaller $aliasInstaller,
        private readonly Poller $poller,
        private readonly Repository $config,
        private readonly EnvFile $envFile,
        private readonly int $readyTimeoutSeconds = 120,
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

        $this->sail->up();

        $ready = $this->poller->until(
            fn (): bool => $this->sail->hasRunningContainers(),
            timeoutSeconds: $this->readyTimeoutSeconds,
            intervalMs: 1000,
        );

        if (! $ready) {
            throw ServerException::startFailed(
                $this->label(),
                "containers did not come up within {$this->readyTimeoutSeconds} seconds",
            );
        }

        $this->aliasInstaller->ensure();
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
     * APP_URL from the .env wins (the loaded config may predate a fresh
     * .env); http://localhost matches Sail's default port-80 binding when
     * neither source has a value.
     */
    public function url(): string
    {
        $url = $this->envFile->valueOr('APP_URL', (string) $this->config->get('app.url'));

        return $url !== '' ? $url : 'http://localhost';
    }
}
