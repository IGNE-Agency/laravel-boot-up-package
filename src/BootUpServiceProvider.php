<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp;

use Igne\LaravelBootUp\Console\DeployCommand;
use Igne\LaravelBootUp\Console\DeployScriptCommand;
use Igne\LaravelBootUp\Console\DownCommand;
use Igne\LaravelBootUp\Console\PipelineCommand;
use Igne\LaravelBootUp\Console\ServeCommand;
use Igne\LaravelBootUp\Database\DatabaseConfig;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Environment\EnvironmentConfig;
use Igne\LaravelBootUp\Environment\ShellProfile;
use Igne\LaravelBootUp\Frontend\FrontendConfig;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\Terminal\LinuxTerminal;
use Igne\LaravelBootUp\Process\Terminal\MacTerminal;
use Igne\LaravelBootUp\Process\Terminal\NullTerminal;
use Igne\LaravelBootUp\Process\Terminal\TerminalLauncher;
use Igne\LaravelBootUp\Queue\QueueConfig;
use Igne\LaravelBootUp\Serve\ServeConfig;
use Igne\LaravelBootUp\Serve\ShutdownRunner;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\ServersConfig;
use Igne\LaravelBootUp\Support\Poller;
use Igne\LaravelBootUp\Tools\ToolsConfig;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Process\Factory;
use Illuminate\Support\ServiceProvider;

final class BootUpServiceProvider extends ServiceProvider
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
        Pipelines\PipelineConfig::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/boot-up.php', 'boot-up');

        foreach (self::CONFIG_CLASSES as $configClass) {
            $this->app->singleton($configClass, fn (Application $app) => $configClass::fromRepository($app['config']));
        }

        $this->app->singleton(ProcessLedger::class, fn (Application $app) => new ProcessLedger(
            $app->storagePath('framework/boot-up/processes.json'),
        ));

        $this->app->singleton(ActiveServerStore::class, fn (Application $app) => new ActiveServerStore(
            $app->storagePath('framework/boot-up/active-server.json'),
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
            logDirectory: $app->storagePath('logs/boot-up'),
            runtimeDirectory: $app->storagePath('framework/boot-up'),
        ));

        $this->app->singleton(EnvFile::class, fn (Application $app) => new EnvFile(
            $app->basePath('.env'),
            $app->basePath('.env.example'),
        ));

        $this->app->singleton(ShellProfile::class, fn () => new ShellProfile);

        $this->app->singleton(PackageJson::class, fn (Application $app) => new PackageJson(
            $app->basePath('package.json'),
        ));

        $this->app->singleton(ComposerJson::class, fn (Application $app) => new ComposerJson(
            $app->basePath('composer.json'),
        ));

        $this->app->singleton(ShutdownRunner::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/boot-up.php' => config_path('boot-up.php'),
        ], 'boot-up-config');

        $this->commands([
            ServeCommand::class,
            DeployCommand::class,
            DeployScriptCommand::class,
            PipelineCommand::class,
            DownCommand::class,
        ]);
    }
}
