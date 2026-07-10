<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Servers\Sail;

use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Servers\CommandRewrites;
use Igne\LaravelBootstrap\Servers\Server;
use Igne\LaravelBootstrap\Servers\ServerException;
use Igne\LaravelBootstrap\Support\Poller;
use Igne\LaravelBootstrap\Tools\Tool;
use Illuminate\Contracts\Config\Repository;

use function Laravel\Prompts\info;

final class SailServer implements Server
{
    public function __construct(
        private readonly Docker $docker,
        private readonly Sail $sail,
        private readonly SailAliasInstaller $aliasInstaller,
        private readonly Poller $poller,
        private readonly Repository $config,
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

    public function requiredTools(): array
    {
        return [Tool::DOCKER];
    }

    public function commandRewrites(): CommandRewrites
    {
        return new CommandRewrites(
            replaces: ['php artisan' => 'artisan'],
            prefixes: ['php', 'composer', 'yarn', 'npm', 'bun', 'artisan', 'node'],
            prefix: './vendor/bin/sail',
        );
    }

    public function start(ServeContext $context): void
    {
        $this->docker->ensureRunning();

        if (! $this->sail->isConfigured()) {
            info('Scaffolding Sail configuration...');
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

    public function url(): string
    {
        return (string) $this->config->get('app.url');
    }
}
