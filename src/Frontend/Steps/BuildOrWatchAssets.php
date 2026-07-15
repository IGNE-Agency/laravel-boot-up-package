<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Frontend\Steps;

use Closure;
use Igne\LaravelBootstrap\Frontend\FrontendConfig;
use Igne\LaravelBootstrap\Frontend\PackageJson;
use Igne\LaravelBootstrap\Frontend\PackageManager;
use Igne\LaravelBootstrap\Frontend\PackageManagerSelector;
use Igne\LaravelBootstrap\Process\ProcessLedger;
use Igne\LaravelBootstrap\Process\ProcessReaper;
use Igne\LaravelBootstrap\Process\ProcessRecord;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\ShellCommand;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\Step;
use Igne\LaravelBootstrap\Servers\CommandRewriter;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

final class BuildOrWatchAssets implements Step
{
    private const LABEL = 'assets-watch';

    public function __construct(
        private readonly FrontendConfig $config,
        private readonly PackageManagerSelector $selector,
        private readonly PackageJson $packageJson,
        private readonly ProcessRunner $runner,
        private readonly CommandRewriter $rewriter,
        private readonly ProcessLedger $ledger,
        private readonly ProcessReaper $reaper,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if (! $context->options->withAssets) {
            note('Assets skipped (--without-assets).');

            return $next($context);
        }

        if ($this->config->assets === 'skip') {
            note('Assets disabled in configuration.');

            return $next($context);
        }

        if (! $this->packageJson->exists()) {
            note('No package.json found — skipping assets.');

            return $next($context);
        }

        $manager = $this->selector->selected();

        $this->config->assets === 'build'
            ? $this->build($context, $manager)
            : $this->watch($context, $manager);

        return $next($context);
    }

    private function build(ServeContext $context, PackageManager $manager): void
    {
        if (! $this->packageJson->hasScript('build')) {
            note("package.json has no 'build' script — skipping the asset build.");

            return;
        }

        info("Building assets with {$manager->value}...");

        $this->runner->run($this->rewrite($context, $manager->runCommand('build')));
    }

    private function watch(ServeContext $context, PackageManager $manager): void
    {
        if (! $this->packageJson->hasScript('dev')) {
            note("package.json has no 'dev' script — skipping the asset watcher.");

            return;
        }

        if ($this->watcherIsRunning()) {
            note('Asset watcher already running — skipping.');

            return;
        }

        $command = $this->rewrite($context, $manager->runCommand('dev'))->withTimeout(null);

        $record = $this->config->watchIn === 'terminal'
            ? $this->runner->startInTerminal($command, self::LABEL)
            : $this->runner->start($command, self::LABEL);

        info("Asset watcher started (PID {$record->pid}) — logs: storage/logs/bootstrap/".self::LABEL.'.log');
    }

    private function watcherIsRunning(): bool
    {
        return $this->ledger->withLabel(self::LABEL)
            ->contains(fn (ProcessRecord $record): bool => $this->reaper->isAlive($record));
    }

    /**
     * @param  list<string>  $tokens
     */
    private function rewrite(ServeContext $context, array $tokens): ShellCommand
    {
        return $this->rewriter->rewrite(ShellCommand::make($tokens), $context->server?->commandRewrites());
    }
}
