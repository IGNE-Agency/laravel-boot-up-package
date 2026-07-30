<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Frontend\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\WorkerDefinition;
use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Serve\WorkerLauncher;
use Igne\LaravelBootUp\Servers\CommandRewriter;

#[Stage(ServeStage::Assets)]
#[Group('assets')]
final class BuildOrWatchAssets implements Step
{
    private const string LABEL = 'assets-watch';

    public function __construct(
        private readonly FrontendConfig $config,
        private readonly PackageManagerSelector $selector,
        private readonly PackageJson $packageJson,
        private readonly ProcessRunner $runner,
        private readonly CommandRewriter $rewriter,
        private readonly WorkerLauncher $launcher,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if (! $context->options->withAssets) {
            terminal()->note('Assets skipped (--without-assets).');

            return $next($context);
        }

        if ($this->config->assets === AssetMode::Skip) {
            terminal()->note('Assets disabled in configuration — skipping.');

            return $next($context);
        }

        if (! $this->packageJson->exists()) {
            terminal()->note('No package.json found — skipping assets.');

            return $next($context);
        }

        $manager = $this->selector->selected();

        $this->config->assets === AssetMode::Build
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

        $this->runner->run($this->rewriter->rewriteFor(
            $context,
            CommandLine::make($manager->runCommand('build')),
        ));
    }

    private function watch(ServeContext $context, PackageManager $manager): void
    {
        if (! $this->packageJson->hasScript('dev')) {
            terminal()->note("package.json has no 'dev' script — skipping the asset watcher.");

            return;
        }

        $this->launcher->launch(new WorkerDefinition(
            label: self::LABEL,
            name: 'Asset watcher',
            tokens: $manager->runCommand('dev'),
            runIn: $this->config->watchIn,
            streamAs: 'vite',
        ), $context);
    }
}
