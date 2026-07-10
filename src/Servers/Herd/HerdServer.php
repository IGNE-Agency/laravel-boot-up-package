<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Servers\Herd;

use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\ShellCommand;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Servers\CommandRewrites;
use Igne\LaravelBootstrap\Servers\Server;
use Igne\LaravelBootstrap\Tools\Tool;

use function Laravel\Prompts\info;

final class HerdServer implements Server
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly HerdServices $services,
        private readonly ?string $projectPath = null,
    ) {}

    public function key(): string
    {
        return 'herd';
    }

    public function label(): string
    {
        return 'Laravel Herd';
    }

    public function requiredTools(): array
    {
        return [Tool::HERD];
    }

    public function commandRewrites(): CommandRewrites
    {
        return new CommandRewrites(
            prefixes: ['php', 'composer', 'tinker'],
            prefix: 'herd',
        );
    }

    public function start(ServeContext $context): void
    {
        $this->runner->runSilently(ShellCommand::make('herd link'));
        info('Project linked to Herd.');

        $this->runner->runSilently(ShellCommand::make('herd secure'));
        info('HTTPS certificate configured.');
    }

    public function isRunning(): bool
    {
        return $this->services->isHealthy();
    }

    public function stop(): void
    {
        $this->runner->run(ShellCommand::make('herd stop'));
    }

    /**
     * Herd serves the linked directory at https://{dirname}.test —
     * config('app.url') is wrong on a fresh .env.
     */
    public function url(): string
    {
        return 'https://'.basename($this->projectPath ?? (getcwd() ?: '')).'.test';
    }
}
