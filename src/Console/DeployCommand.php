<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Serve\ServeConfig;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\ServeOptions;
use Igne\LaravelBootUp\Support\BootUpException;
use Igne\LaravelBootUp\Support\Platform;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;

use Throwable;

final class DeployCommand extends Command implements Isolatable
{
    protected $signature = 'app:deploy
        {--s|seed : Seed the database after migrating}
        {--no-migrate : Skip running pending migrations}
        {--fresh : Drop all tables and re-run every migration (asks first)}
        {--u|update : Update dependencies instead of installing}';

    protected $description = 'Install dependencies, run project commands and migrate — without booting a server';

    public function handle(ServeConfig $config, Platform $platform, Pipeline $pipeline): int
    {
        if ($platform->isWindows()) {
            error('app:deploy is not supported on native Windows. Run it inside WSL2.');

            return self::FAILURE;
        }

        intro('Deploying the application...');

        $options = new ServeOptions(
            seed: (bool) $this->option('seed'),
            migrate: ! $this->option('no-migrate'),
            update: (bool) $this->option('update'),
            fresh: (bool) $this->option('fresh'),
        );

        try {
            $pipeline->send(new ServeContext($options))->through($config->deploySteps)->thenReturn();
        } catch (BootUpException|ProcessFailedException|ProcessTimedOutException $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            error('Unexpected error: '.$exception->getMessage());

            return self::FAILURE;
        }

        outro('Deploy complete.');

        return self::SUCCESS;
    }
}
