<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Services\Steps;

use Closure;
use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRecord;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Igne\LaravelBootUp\Services\ServicesConfig;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

/**
 * Starts a tracked Reverb WebSocket server when laravel/reverb is a
 * project dependency. Detect-and-skip, like Horizon.
 */
final class StartReverb implements Step
{
    private const LABEL = 'reverb';

    public function __construct(
        private readonly ServicesConfig $config,
        private readonly ComposerJson $composerJson,
        private readonly ProcessRunner $runner,
        private readonly CommandRewriter $rewriter,
        private readonly ProcessLedger $ledger,
        private readonly ProcessReaper $reaper,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if (! $this->config->reverbEnabled || ! $this->composerJson->requires('laravel/reverb')) {
            return $next($context);
        }

        if ($this->alreadyRunning()) {
            note('Reverb already running — skipping.');

            return $next($context);
        }

        $record = $this->runner->start(
            $this->rewriter->rewrite(
                ShellCommand::make(['php', 'artisan', 'reverb:start'])->withTimeout(null),
                $context->server?->commandRewrites(),
            ),
            self::LABEL,
        );

        info("Reverb started (PID {$record->pid}) — logs: storage/logs/boot-up/".self::LABEL.'.log');

        return $next($context);
    }

    private function alreadyRunning(): bool
    {
        return $this->ledger->withLabel(self::LABEL)
            ->contains(fn (ProcessRecord $record): bool => $this->reaper->isAlive($record));
    }
}
