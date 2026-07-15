<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Artisan;

use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRecord;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Servers\CommandRewrites;
use Igne\LaravelBootUp\Servers\Server;
use Igne\LaravelBootUp\Tools\Tool;
use Illuminate\Contracts\Config\Repository;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

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
        private readonly Repository $config,
    ) {}

    public function key(): string
    {
        return 'laravel';
    }

    public function label(): string
    {
        return 'Laravel (php artisan serve)';
    }

    /**
     * @return list<Tool>
     */
    public function requiredTools(): array
    {
        return [];
    }

    public function commandRewrites(): CommandRewrites
    {
        return CommandRewrites::none();
    }

    public function start(ServeContext $context): void
    {
        if ($this->isRunning()) {
            note('php artisan serve is already running.');

            return;
        }

        $record = $this->runner->start(
            ShellCommand::make('php artisan serve')->withTimeout(null),
            self::LABEL,
        );

        info("php artisan serve started (PID {$record->pid}).");
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

    public function url(): string
    {
        $url = (string) $this->config->get('app.url');

        return $url !== '' ? $url : 'http://127.0.0.1:8000';
    }
}
