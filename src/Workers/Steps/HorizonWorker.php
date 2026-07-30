<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Workers\Steps;

use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Concerns\LaunchesAsWorker;
use Igne\LaravelBootUp\Config\HorizonConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Contracts\Worker;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Enums\RunMode;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Serve\WorkerLauncher;
use Igne\LaravelBootUp\Workers\HorizonPresence;

/**
 * A tracked Horizon supervisor when laravel/horizon is a project
 * dependency. Detect-and-skip: projects without Horizon never notice
 * this step.
 */
#[Stage(ServeStage::Services)]
#[Group('workers')]
final class HorizonWorker implements Step, Worker
{
    use LaunchesAsWorker;

    private const string LABEL = 'horizon';

    public function __construct(
        private readonly HorizonConfig $config,
        private readonly HorizonPresence $presence,
        private readonly WorkerLauncher $workers,
    ) {}

    public function label(): string
    {
        return self::LABEL;
    }

    public function name(): string
    {
        return 'Horizon';
    }

    public function command(): CommandLine
    {
        return CommandLine::make(['php', 'artisan', 'horizon']);
    }

    public function runIn(): RunMode
    {
        return $this->config->runIn;
    }

    protected function shouldRun(ServeContext $context): bool
    {
        return $this->presence->managesQueue();
    }

    protected function launcher(): WorkerLauncher
    {
        return $this->workers;
    }
}
