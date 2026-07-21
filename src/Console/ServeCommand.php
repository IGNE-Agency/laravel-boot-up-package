<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Facades\Platform;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Serve\ServeConfig;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\ServeOptions;
use Igne\LaravelBootUp\Serve\ServePlan;
use Igne\LaravelBootUp\Serve\ServeProcessProbe;
use Igne\LaravelBootUp\Serve\ShutdownRunner;
use Igne\LaravelBootUp\Serve\StageReporter;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\ServerSelector;
use Igne\LaravelBootUp\Support\BootUpException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Throwable;

final class ServeCommand extends Command implements Isolatable
{
    protected $signature = 'app:serve {server? : The development server to use (herd, sail, laravel, or any driver registered in boot-up.server.drivers)}
        {--s|seed : Seed the database after migrating}
        {--no-migrate : Skip running pending migrations}
        {--fresh : Drop all tables and re-run every migration (asks first)}
        {--u|update : Update dependencies instead of installing}
        {--without-queue : Do not start a queue worker}
        {--without-assets : Skip frontend dependencies and assets}';

    protected $description = 'Boot everything the application needs and serve it locally';

    public function handle(
        ServerSelector $selector,
        ServeConfig $config,
        ShutdownRunner $shutdown,
        ActiveServerStore $store,
        ServeProcessProbe $probe,
        ProcessReaper $reaper,
        Pipeline $pipeline,
        StageReporter $reporter,
    ): int {
        if (Platform::isWindows()) {
            terminal()->error('app:serve is not supported on native Windows. Run it inside WSL2.');

            return self::FAILURE;
        }

        if ($this->anotherServeIsRunning($store, $probe)) {
            terminal()->warning('Another app:serve is already running for this project. Aborting.');

            return self::FAILURE;
        }

        $reaper->prune();

        terminal()->intro('Booting the application...');

        $context = new ServeContext($this->serveOptions(), $selector->select($this->argument('server')));

        $plan = ServePlan::for($config->serveSteps, $context->options, $context->server?->label());

        terminal()->section('What app:serve will do');
        terminal()->list($plan->summary());

        $pipes = $reporter->begin($plan);

        // The trap must be registered AFTER begin(): Progress::start()
        // installs its own SIGINT handler, which would otherwise replace
        // this one and skip the shutdown entirely.
        $this->trap([SIGINT, SIGTERM], function () use ($shutdown, $reporter): void {
            $reporter->interrupt();
            $shutdown->run();
            exit(self::SUCCESS);
        });

        try {
            $pipeline->send($context)->through($pipes)->thenReturn();
        } catch (BootUpException|ProcessFailedException|ProcessTimedOutException $exception) {
            $reporter->fail();
            terminal()->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $reporter->fail();
            terminal()->error('Unexpected error: '.$exception->getMessage());
            terminal()->note('Background processes may still be running — clean up with: php artisan app:down');

            return self::FAILURE;
        }

        $reporter->finish();
        terminal()->outro('Application ready.');

        return self::SUCCESS;
    }

    private function serveOptions(): ServeOptions
    {
        return new ServeOptions(
            seed: (bool) $this->option('seed'),
            migrate: ! $this->option('no-migrate'),
            update: (bool) $this->option('update'),
            withQueue: ! $this->option('without-queue'),
            withAssets: ! $this->option('without-assets'),
            fresh: (bool) $this->option('fresh'),
        );
    }

    private function anotherServeIsRunning(ActiveServerStore $store, ServeProcessProbe $probe): bool
    {
        $active = $store->current();

        if ($active === null || $active->servePid === getmypid()) {
            return false;
        }

        return $probe->isServing($active->servePid);
    }
}
