<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Artisan;

use Igne\LaravelBootUp\Config\ArtisanServeConfig;
use Igne\LaravelBootUp\Contracts\ProvidesDevProcess;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Process\ProcessReaper;

/**
 * Serves through `php artisan serve`.
 *
 * The serve command is a dev process, not something the setup starts: the
 * multiplexer owns its output and restarts it if it dies, and a detached run
 * starts it from the registry like any other. So there is nothing to do here
 * beyond saying which command runs it.
 */
final class ArtisanServer implements ProvidesDevProcess, Server
{
    private const string LABEL = 'artisan-serve';

    public function __construct(
        private readonly ProcessReaper $reaper,
        private readonly ArtisanServeConfig $config,
    ) {}

    public function key(): string
    {
        return 'artisan';
    }

    public function label(): string
    {
        return 'Laravel (php artisan serve)';
    }

    public function start(BootContext $context): void
    {
        terminal()->note($this->isRunning()
            ? 'php artisan serve is already running.'
            : 'php artisan serve runs with the dev processes.');
    }

    /**
     * Runs as the [server] process, exactly as it does under plain
     * `php artisan dev`. Null only when a tracked serve is already alive --
     * one a detached run started, or one that outlived its terminal.
     */
    public function devProcess(BootContext $context): ?CommandLine
    {
        return $this->isRunning() ? null : $this->serveCommand();
    }

    private function serveCommand(): CommandLine
    {
        return CommandLine::make([
            'php', 'artisan', 'serve',
            "--host={$this->config->host}",
            "--port={$this->config->port}",
        ])->withTimeout(null);
    }

    public function isRunning(): bool
    {
        return $this->reaper->isRunning(self::LABEL);
    }

    public function stop(): void
    {
        $this->reaper->stop(self::LABEL);
    }

    /**
     * Derived from the configured bind address — `php artisan serve` never
     * honors APP_URL, so consulting app.url would announce a URL the
     * server does not actually listen on.
     */
    public function url(): string
    {
        return "http://{$this->config->host}:{$this->config->port}";
    }
}
