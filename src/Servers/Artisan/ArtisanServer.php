<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Artisan;

use Igne\LaravelBootUp\Config\ServersConfig;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ShellCommand;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRunner;

/**
 * Serves via a tracked, detached `php artisan serve` process. Key stays
 * 'laravel' for backwards compatibility with existing config and args.
 */
final class ArtisanServer implements Server
{
    private const string LABEL = 'artisan-serve';

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly ProcessLedger $ledger,
        private readonly ProcessReaper $reaper,
        private readonly ServersConfig $config,
    ) {}

    public function key(): string
    {
        return 'laravel';
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

        $record = $this->runner->start(
            ShellCommand::make([
                'php', 'artisan', 'serve',
                "--host={$this->config->artisanHost}",
                "--port={$this->config->artisanPort}",
            ])->withTimeout(null),
            self::LABEL,
        );

        terminal()->success("php artisan serve started (PID {$record->pid}).");
    }

    public function isRunning(): bool
    {
        return $this->ledger->withLabel(self::LABEL)
            ->contains(fn (ProcessRecord $record): bool => $this->reaper->isAlive($record));
    }

    public function stop(): void
    {
        $this->ledger->withLabel(self::LABEL)
            ->each(fn (ProcessRecord $record) => $this->reaper->reap($record));
    }

    /**
     * Derived from the configured bind address — `php artisan serve` never
     * honors APP_URL, so consulting app.url would announce a URL the
     * server does not actually listen on.
     */
    public function url(): string
    {
        return "http://{$this->config->artisanHost}:{$this->config->artisanPort}";
    }
}
