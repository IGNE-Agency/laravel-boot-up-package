<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Artisan;

use Igne\LaravelBootUp\Config\ArtisanServeConfig;
use Igne\LaravelBootUp\Contracts\ProvidesDevProcess;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRunner;

/**
 * Serves through `php artisan serve`.
 *
 * In the foreground it runs as the [server] dev process, so the multiplexer
 * owns its output and restarts it if it dies. A detached boot has no
 * multiplexer to run it, so it starts a tracked background process instead.
 */
final class ArtisanServer implements ProvidesDevProcess, Server
{
    private const string LABEL = 'artisan-serve';

    public function __construct(
        private readonly ProcessRunner $runner,
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

    public function start(ServeContext $context): void
    {
        if ($this->isRunning()) {
            terminal()->note('php artisan serve is already running.');

            return;
        }

        // A foreground boot runs the server as a dev process instead, which
        // starts once the pipeline is done. Migrations and the rest of the
        // boot never needed the HTTP server to be up.
        if ($context->options->follow) {
            terminal()->note('php artisan serve starts with the dev processes.');

            return;
        }

        $record = $this->runner->start($this->serveCommand(), self::LABEL);

        terminal()->success("php artisan serve started (PID {$record->pid}).");
    }

    /**
     * Runs as the [server] process when this boot stays in the foreground.
     * A detached run has already started the tracked process in start(),
     * and an application that was serving before this boot keeps that one.
     */
    public function devProcess(ServeContext $context): ?CommandLine
    {
        if (! $context->options->follow || $this->isRunning()) {
            return null;
        }

        return $this->serveCommand();
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
