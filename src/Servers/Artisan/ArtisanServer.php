<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Artisan;

use Igne\LaravelBootUp\Config\ArtisanServeConfig;
use Igne\LaravelBootUp\Contracts\ProvidesDevProcess;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\CombinedService;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Serve\CombinedRunPlan;
use Igne\LaravelBootUp\Serve\WorkerLauncher;

/**
 * Serves via a tracked, detached `php artisan serve` process.
 */
final class ArtisanServer implements ProvidesDevProcess, Server
{
    private const string LABEL = 'artisan-serve';

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly WorkerLauncher $launcher,
        private readonly ArtisanServeConfig $config,
        private readonly CombinedRunPlan $plan,
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
            $this->queueServerStream();

            return;
        }

        $record = $this->runner->start(
            CommandLine::make([
                'php', 'artisan', 'serve',
                "--host={$this->config->host}",
                "--port={$this->config->port}",
            ])->withTimeout(null),
            self::LABEL,
        );

        terminal()->success("php artisan serve started (PID {$record->pid}).");
        $this->queueServerStream();
    }

    /**
     * The serve process must be up before migrations run, so it starts
     * detached as always — its log is tailed into the combined stream as
     * [server] instead. Queued unconditionally: ServeCommand only streams
     * when actual combined processes exist, and a tail alone never holds
     * the stream open.
     */
    private function queueServerStream(): void
    {
        $this->plan->add(CombinedService::tail(self::LABEL, 'server', $this->runner->logFile(self::LABEL)));
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

        return CommandLine::make([
            'php', 'artisan', 'serve',
            "--host={$this->config->host}",
            "--port={$this->config->port}",
        ])->withTimeout(null);
    }

    public function isRunning(): bool
    {
        return $this->launcher->isRunning(self::LABEL);
    }

    public function stop(): void
    {
        $this->launcher->stop(self::LABEL);
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
