<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Console;

use Igne\LaravelBootstrap\Serve\ServeConfig;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\ServeOptions;
use Igne\LaravelBootstrap\Support\BootstrapException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;

final class DeployCommand extends Command implements Isolatable
{
    protected $signature = 'app:deploy
        {--s|seed : Seed the database after migrating}
        {--no-migrate : Skip running pending migrations}
        {--u|update : Update dependencies instead of installing}';

    protected $description = 'Install dependencies, run project commands and migrate — without booting a server';

    public function handle(ServeConfig $config, Pipeline $pipeline): int
    {
        intro('Deploying the application...');

        $options = new ServeOptions(
            seed: (bool) $this->option('seed'),
            migrate: ! $this->option('no-migrate'),
            update: (bool) $this->option('update'),
        );

        try {
            $pipeline->send(new ServeContext($options))->through($config->deploySteps)->thenReturn();
        } catch (BootstrapException|ProcessFailedException|ProcessTimedOutException $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        outro('Deploy complete.');

        return self::SUCCESS;
    }
}
