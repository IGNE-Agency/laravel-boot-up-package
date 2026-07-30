<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Frontend\Steps;

use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Concerns\LaunchesAsWorker;
use Igne\LaravelBootUp\Concerns\SkipsDisabledAssets;
use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Contracts\Worker;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Enums\RunMode;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Serve\WorkerLauncher;

/**
 * The tracked asset watcher (`run dev`), the AssetMode::Watch default.
 * Voices every shared skip except an explicit Build mode, which its
 * BuildAssets sibling owns — the two never note twice.
 */
#[Stage(ServeStage::Assets)]
#[Group('assets')]
final class WatchAssets implements Step, Worker
{
    use LaunchesAsWorker;
    use SkipsDisabledAssets;

    /** Public so ShutdownRunner's stale-hot-file cleanup shares the exact label. */
    public const string LABEL = 'assets-watch';

    public function __construct(
        private readonly FrontendConfig $config,
        private readonly PackageManagerSelector $selector,
        private readonly PackageJson $packageJson,
        private readonly WorkerLauncher $workers,
    ) {}

    public function label(): string
    {
        return self::LABEL;
    }

    public function name(): string
    {
        return 'Asset watcher';
    }

    public function command(): CommandLine
    {
        return CommandLine::make($this->selector->selected()->runCommand('dev'));
    }

    public function runIn(): RunMode
    {
        return $this->config->watchIn;
    }

    public function streamName(): string
    {
        return 'vite';
    }

    protected function shouldRun(ServeContext $context): bool
    {
        if ($this->config->assets === AssetMode::Build) {
            return false;
        }

        $reason = $this->sharedAssetSkipReason($context)
            ?? ($this->packageJson->hasScript('dev') ? null : "package.json has no 'dev' script — skipping the asset watcher.");

        if ($reason !== null) {
            terminal()->note($reason);

            return false;
        }

        return true;
    }

    protected function launcher(): WorkerLauncher
    {
        return $this->workers;
    }
}
