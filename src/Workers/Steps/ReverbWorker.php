<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Workers\Steps;

use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Concerns\LaunchesAsWorker;
use Igne\LaravelBootUp\Config\ReverbConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Contracts\Worker;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Enums\RunMode;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Serve\WorkerLauncher;

/**
 * A tracked Reverb WebSocket server when laravel/reverb is a project
 * dependency. Detect-and-skip: projects without Reverb never notice
 * this step.
 */
#[Stage(ServeStage::Services)]
#[Group('workers')]
final class ReverbWorker implements Step, Worker
{
    use LaunchesAsWorker;

    private const string LABEL = 'reverb';

    public function __construct(
        private readonly ReverbConfig $config,
        private readonly ComposerJson $composerJson,
        private readonly WorkerLauncher $workers,
    ) {}

    public function label(): string
    {
        return self::LABEL;
    }

    public function name(): string
    {
        return 'Reverb';
    }

    public function command(): CommandLine
    {
        return CommandLine::make(['php', 'artisan', 'reverb:start']);
    }

    public function runIn(): RunMode
    {
        return $this->config->runIn;
    }

    protected function shouldRun(ServeContext $context): bool
    {
        return $this->config->enabled && $this->composerJson->requires('laravel/reverb');
    }

    protected function launcher(): WorkerLauncher
    {
        return $this->workers;
    }
}
