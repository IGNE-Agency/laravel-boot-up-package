<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Facades\Platform;
use Igne\LaravelBootUp\Serve\ServeConfig;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\ServeOptions;
use Igne\LaravelBootUp\Support\BootUpException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Throwable;

final class DeployCommand extends Command implements Isolatable
{
    protected $signature = 'app:deploy
        {--s|seed : Seed the database after migrating}
        {--no-migrate : Skip running pending migrations}
        {--fresh : Drop all tables and re-run every migration (asks first)}
        {--u|update : Update dependencies instead of installing}';

    protected $description = 'Install dependencies, run project commands and migrate — without booting a server';

    public function handle(ServeConfig $config, Pipeline $pipeline): int
    {
        if (Platform::isWindows()) {
            terminal()->error('app:deploy is not supported on native Windows. Run it inside WSL2.');

            return self::FAILURE;
        }

        terminal()->intro('Deploying the application...');

        $options = new ServeOptions(
            seed: (bool) $this->option('seed'),
            migrate: ! $this->option('no-migrate'),
            update: (bool) $this->option('update'),
            fresh: (bool) $this->option('fresh'),
        );

        try {
            $pipeline->send(new ServeContext($options))->through($config->deploySteps)->thenReturn();
        } catch (BootUpException|ProcessFailedException|ProcessTimedOutException $exception) {
            terminal()->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            terminal()->error('Unexpected error: '.$exception->getMessage());

            return self::FAILURE;
        }

        terminal()->outro('Deploy complete.');

        return self::SUCCESS;
    }
}
