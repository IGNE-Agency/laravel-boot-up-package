<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Frontend\Steps;

use Closure;
use Igne\LaravelBootUp\Frontend\FrontendConfig;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\PackageManager;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRecord;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;
use Igne\LaravelBootUp\Servers\CommandRewriter;

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
            terminal()->note('Assets skipped (--without-assets).');

            return $next($context);
        }

        if ($this->config->assets === 'skip') {
            terminal()->note('Assets disabled in configuration.');

            return $next($context);
        }

        if (! $this->packageJson->exists()) {
            terminal()->note('No package.json found — skipping assets.');

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
            terminal()->note("package.json has no 'build' script — skipping the asset build.");

            return;
        }

        terminal()->info("Building assets with {$manager->value}...");

        $this->runner->run($this->rewrite($context, $manager->runCommand('build')));
    }

    private function watch(ServeContext $context, PackageManager $manager): void
    {
        if (! $this->packageJson->hasScript('dev')) {
            terminal()->note("package.json has no 'dev' script — skipping the asset watcher.");

            return;
        }

        if ($this->watcherIsRunning()) {
            terminal()->note('Asset watcher already running — skipping.');

            return;
        }

        $command = $this->rewrite($context, $manager->runCommand('dev'))->withTimeout(null);

        $record = $this->config->watchIn === 'terminal'
            ? $this->runner->startInTerminal($command, self::LABEL)
            : $this->runner->start($command, self::LABEL);

        terminal()->success("Asset watcher started (PID {$record->pid}) — logs: storage/logs/boot-up/".self::LABEL.'.log');
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
