<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Frontend\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Concerns\SkipsDisabledAssets;
use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\CommandRewriter;

/**
 * One synchronous asset build, for projects that want compiled assets
 * without a watcher. Runs only under AssetMode::Build — its WatchAssets
 * sibling voices every other skip, so the two never note twice.
 */
#[Stage(ServeStage::Assets)]
#[Group('assets')]
final class BuildAssets implements Step
{
    use SkipsDisabledAssets;

    public function __construct(
        private readonly FrontendConfig $config,
        private readonly PackageManagerSelector $selector,
        private readonly PackageJson $packageJson,
        private readonly ProcessRunner $runner,
        private readonly CommandRewriter $rewriter,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if ($this->config->assets !== AssetMode::Build) {
            return $next($context);
        }

        $reason = $this->sharedAssetSkipReason($context);

        if ($reason !== null) {
            terminal()->note($reason);

            return $next($context);
        }

        if (! $this->packageJson->hasScript('build')) {
            terminal()->note("package.json has no 'build' script — skipping the asset build.");

            return $next($context);
        }

        $manager = $this->selector->selected();

        terminal()->info("Building assets with {$manager->value}...");

        $this->runner->run($this->rewriter->rewriteFor(
            $context,
            CommandLine::make($manager->runCommand('build')),
        ));

        return $next($context);
    }
}
