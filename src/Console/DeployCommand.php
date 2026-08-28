<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Boot\StageReporter;
use Igne\LaravelBootUp\Boot\StepSequence;
use Igne\LaravelBootUp\Config\DeployConfig;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Pipeline\Pipeline;

final class DeployCommand extends BootUpCommand implements Isolatable
{
    protected $signature = 'app:deploy
        {--s|seed : Seed the database after migrating}
        {--no-migrate : Skip running pending migrations}
        {--fresh : Drop all tables and re-run every migration (asks first)}
        {--u|update : Update dependencies instead of installing}
        {--y|yes : Run without the confirmation prompt}';

    protected $description = 'Install dependencies, run project commands and migrate — without booting a server';

    private ?StageReporter $reporter = null;

    protected function requiresUnix(): bool
    {
        return true;
    }

    public function handle(DeployConfig $config, Pipeline $pipeline, StageReporter $reporter): int
    {
        $this->announce('Deploying the application...');

        $options = new BootOptions(
            seed: (bool) $this->option('seed'),
            migrate: ! $this->option('no-migrate'),
            update: (bool) $this->option('update'),
            fresh: (bool) $this->option('fresh'),
        );

        $plan = StepSequence::for($config->steps, $options);

        if (! $this->confirmPlan($plan, 'app:deploy', $config->autoAccept)) {
            return $this->skip('Aborted — nothing was changed.');
        }

        $this->reporter = $reporter;
        $pipes = $reporter->begin($plan);

        $pipeline->send(new BootContext($options))->through($pipes)->thenReturn();

        $reporter->finish();

        return $this->done('Deploy complete.');
    }

    protected function onFailure(): void
    {
        $this->reporter?->fail();
    }
}
