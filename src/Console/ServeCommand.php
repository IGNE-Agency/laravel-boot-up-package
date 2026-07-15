<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Console;

use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\ShellCommand;
use Igne\LaravelBootstrap\Serve\ServeConfig;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\ServeOptions;
use Igne\LaravelBootstrap\Serve\ShutdownRunner;
use Igne\LaravelBootstrap\Servers\ActiveServerStore;
use Igne\LaravelBootstrap\Servers\ServerSelector;
use Igne\LaravelBootstrap\Support\BootstrapException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\warning;

final class ServeCommand extends Command implements Isolatable
{
    protected $signature = 'app:serve {server? : The development server to use (herd, sail, laravel)}
        {--s|seed : Seed the database after migrating}
        {--no-migrate : Skip running pending migrations}
        {--u|update : Update dependencies instead of installing}
        {--without-queue : Do not start a queue worker}
        {--without-assets : Skip frontend dependencies and assets}';

    protected $description = 'Boot everything the application needs and serve it locally';

    public function handle(
        ServerSelector $selector,
        ServeConfig $config,
        ShutdownRunner $shutdown,
        ActiveServerStore $store,
        ProcessRunner $runner,
        Pipeline $pipeline,
    ): int {
        if ($this->anotherServeIsRunning($store, $runner)) {
            warning('Another app:serve is already running for this project. Aborting.');

            return self::FAILURE;
        }

        intro('Booting the application...');

        $this->trap([SIGINT, SIGTERM], function () use ($shutdown): void {
            $shutdown->run();
            exit(self::SUCCESS);
        });

        $context = new ServeContext($this->serveOptions(), $selector->select($this->argument('server')));

        try {
            $pipeline->send($context)->through($config->serveSteps)->thenReturn();
        } catch (BootstrapException|ProcessFailedException|ProcessTimedOutException $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

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
        );
    }

    private function anotherServeIsRunning(ActiveServerStore $store, ProcessRunner $runner): bool
    {
        $active = $store->current();

        if ($active === null || $active->servePid === getmypid()) {
            return false;
        }

        $command = trim($runner->runSilently(
            ShellCommand::make(['ps', '-p', (string) $active->servePid, '-o', 'command=']),
        )->output());

        return str_contains($command, 'app:serve');
    }
}
