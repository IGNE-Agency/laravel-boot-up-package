<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap;

use Igne\LaravelBootstrap\Console\DeployCommand;
use Igne\LaravelBootstrap\Console\DownCommand;
use Igne\LaravelBootstrap\Console\ServeCommand;
use Igne\LaravelBootstrap\Database\DatabaseConfig;
use Igne\LaravelBootstrap\Environment\EnvFile;
use Igne\LaravelBootstrap\Environment\EnvironmentConfig;
use Igne\LaravelBootstrap\Environment\ShellProfile;
use Igne\LaravelBootstrap\Frontend\FrontendConfig;
use Igne\LaravelBootstrap\Frontend\PackageJson;
use Igne\LaravelBootstrap\Process\ProcessLedger;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\Terminal\LinuxTerminal;
use Igne\LaravelBootstrap\Process\Terminal\MacTerminal;
use Igne\LaravelBootstrap\Process\Terminal\NullTerminal;
use Igne\LaravelBootstrap\Process\Terminal\TerminalLauncher;
use Igne\LaravelBootstrap\Queue\QueueConfig;
use Igne\LaravelBootstrap\Serve\ServeConfig;
use Igne\LaravelBootstrap\Serve\ShutdownRunner;
use Igne\LaravelBootstrap\Servers\ActiveServerStore;
use Igne\LaravelBootstrap\Servers\ServersConfig;
use Igne\LaravelBootstrap\Support\Poller;
use Igne\LaravelBootstrap\Tools\ToolsConfig;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Process\Factory;
use Illuminate\Support\ServiceProvider;

final class BootstrapServiceProvider extends ServiceProvider
{
    private const CONFIG_CLASSES = [
        ServeConfig::class,
        ServersConfig::class,
        ToolsConfig::class,
        DatabaseConfig::class,
        FrontendConfig::class,
        QueueConfig::class,
        EnvironmentConfig::class,
        Deploy\DeployConfig::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bootstrap.php', 'bootstrap');

        foreach (self::CONFIG_CLASSES as $configClass) {
            $this->app->singleton($configClass, fn (Application $app) => $configClass::fromRepository($app['config']));
        }

        $this->app->singleton(ProcessLedger::class, fn (Application $app) => new ProcessLedger(
            $app->storagePath('framework/bootstrap/processes.json'),
        ));

        $this->app->singleton(ActiveServerStore::class, fn (Application $app) => new ActiveServerStore(
            $app->storagePath('framework/bootstrap/active-server.json'),
        ));

        $this->app->singleton(TerminalLauncher::class, fn (Application $app) => match (PHP_OS_FAMILY) {
            'Darwin' => $app->make(MacTerminal::class),
            'Linux' => $app->make(LinuxTerminal::class),
            default => new NullTerminal,
        });

        $this->app->singleton(ProcessRunner::class, fn (Application $app) => new ProcessRunner(
            processes: $app->make(Factory::class),
            ledger: $app->make(ProcessLedger::class),
            terminal: $app->make(TerminalLauncher::class),
            poller: $app->make(Poller::class),
            logDirectory: $app->storagePath('logs/bootstrap'),
            runtimeDirectory: $app->storagePath('framework/bootstrap'),
        ));

        $this->app->singleton(EnvFile::class, fn (Application $app) => new EnvFile(
            $app->basePath('.env'),
            $app->basePath('.env.example'),
        ));

        $this->app->singleton(ShellProfile::class, fn () => new ShellProfile);

        $this->app->singleton(PackageJson::class, fn (Application $app) => new PackageJson(
            $app->basePath('package.json'),
        ));

        $this->app->singleton(ShutdownRunner::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/bootstrap.php' => config_path('bootstrap.php'),
        ], 'bootstrap-config');

        $this->commands([
            ServeCommand::class,
            DeployCommand::class,
            DownCommand::class,
        ]);
    }
}
