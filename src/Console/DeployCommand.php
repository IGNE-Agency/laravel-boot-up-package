<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Serve\ServeConfig;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\ServeOptions;
use Igne\LaravelBootUp\Serve\StageReporter;
use Igne\LaravelBootUp\Serve\StepSequence;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Pipeline\Pipeline;

final class DeployCommand extends BootUpCommand implements Isolatable
{
    protected bool $requiresUnix = true;

    protected $signature = 'app:deploy
        {--s|seed : Seed the database after migrating}
        {--no-migrate : Skip running pending migrations}
        {--fresh : Drop all tables and re-run every migration (asks first)}
        {--u|update : Update dependencies instead of installing}
        {--y|yes : Run without the confirmation prompt}';

    protected $description = 'Install dependencies, run project commands and migrate — without booting a server';

    private ?StageReporter $reporter = null;

    public function perform(ServeConfig $config, Pipeline $pipeline, StageReporter $reporter): int
    {
        terminal()->intro('Deploying the application...');

        $options = new ServeOptions(
            seed: (bool) $this->option('seed'),
            migrate: ! $this->option('no-migrate'),
            update: (bool) $this->option('update'),
            fresh: (bool) $this->option('fresh'),
        );

        $plan = StepSequence::for($config->deploySteps, $options);

        if (! $this->confirmPlan($plan, 'app:deploy', $config->autoAccept)) {
            terminal()->note('Aborted — nothing was changed.');

            return self::SUCCESS;
        }

        $this->reporter = $reporter;
        $pipes = $reporter->begin($plan);

        $pipeline->send(new ServeContext($options))->through($pipes)->thenReturn();

        $reporter->finish();
        terminal()->outro('Deploy complete.');

        return self::SUCCESS;
    }

    protected function onFailure(): void
    {
        $this->reporter?->fail();
    }
}
