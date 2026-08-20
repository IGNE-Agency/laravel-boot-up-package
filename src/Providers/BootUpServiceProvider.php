<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Providers;

use Igne\LaravelBootUp\Config\ArtisanServeConfig;
use Igne\LaravelBootUp\Config\DatabaseConfig;
use Igne\LaravelBootUp\Config\DeployConfig;
use Igne\LaravelBootUp\Config\DevConfig;
use Igne\LaravelBootUp\Config\DevServerConfig;
use Igne\LaravelBootUp\Config\EnvironmentConfig;
use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Config\HerdConfig;
use Igne\LaravelBootUp\Config\HorizonConfig;
use Igne\LaravelBootUp\Config\PipelineConfig;
use Igne\LaravelBootUp\Config\ProcessConfig;
use Igne\LaravelBootUp\Config\QueueConfig;
use Igne\LaravelBootUp\Config\ReverbConfig;
use Igne\LaravelBootUp\Config\SailConfig;
use Igne\LaravelBootUp\Config\SchedulerConfig;
use Igne\LaravelBootUp\Config\ServeConfig;
use Igne\LaravelBootUp\Config\ShutdownConfig;
use Igne\LaravelBootUp\Config\ToolsConfig;
use Igne\LaravelBootUp\Console\DeployCommand;
use Igne\LaravelBootUp\Console\DeployScriptCommand;
use Igne\LaravelBootUp\Console\DevCommand;
use Igne\LaravelBootUp\Console\DownCommand;
use Igne\LaravelBootUp\Console\GenerateGitHooksCommand;
use Igne\LaravelBootUp\Console\PipelineCommand;
use Igne\LaravelBootUp\Console\StatusCommand;
use Igne\LaravelBootUp\Contracts\TerminalLauncher;
use Igne\LaravelBootUp\Deploy\Composer;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Environment\ShellProfile;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Process\LinuxTerminalLauncher;
use Igne\LaravelBootUp\Process\MacTerminalLauncher;
use Igne\LaravelBootUp\Process\NullTerminalLauncher;
use Igne\LaravelBootUp\Process\OutputMultiplexer;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Serve\BootCommandRegistry;
use Igne\LaravelBootUp\Serve\CombinedRunPlan;
use Igne\LaravelBootUp\Serve\ShutdownRunner;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\Herd\HerdSites;
use Igne\LaravelBootUp\Services\GeneratedFilePublisher;
use Igne\LaravelBootUp\Services\LockfileConflictDetector;
use Igne\LaravelBootUp\Services\Platform;
use Igne\LaravelBootUp\Services\Poller;
use Igne\LaravelBootUp\Services\Terminal;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\DevCommand as FrameworkDevCommand;
use Illuminate\Process\Factory;
use Illuminate\Support\ServiceProvider;

final class BootUpServiceProvider extends ServiceProvider
{
    private const array CONFIG_CLASSES = [
        ArtisanServeConfig::class,
        DatabaseConfig::class,
        DeployConfig::class,
        DevConfig::class,
        DevServerConfig::class,
        EnvironmentConfig::class,
        FrontendConfig::class,
        HerdConfig::class,
        HorizonConfig::class,
        PipelineConfig::class,
        ProcessConfig::class,
        QueueConfig::class,
        ReverbConfig::class,
        SailConfig::class,
        SchedulerConfig::class,
        ServeConfig::class,
        ShutdownConfig::class,
        ToolsConfig::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/boot-up.php', 'boot-up');

        $this->registerConfigObjects();
        $this->registerTerminal();
        $this->registerProcessTracking();
        $this->registerHerd();
        $this->registerProjectFiles();

        $this->registerDevCommand();

        $this->app->singleton(PackageManagerSelector::class);
        $this->app->singleton(ShutdownRunner::class);
        $this->app->singleton(CombinedRunPlan::class);

        // Bound in register() so providers can call BootCommands::…
        // from their boot() regardless of boot order.
        $this->app->singleton(BootCommandRegistry::class, fn (Application $app): BootCommandRegistry => new BootCommandRegistry(
            runningInConsole: $app->runningInConsole(),
            vendorPath: $app->basePath('vendor'),
        ));
    }

    /**
     * Take over `php artisan dev` rather than shipping a command beside it.
     *
     * Artisan resolves the name through the framework's class string, so
     * extending that binding puts boot-up's subclass behind it — and the
     * terminal UI, its flags and every later upstream improvement keep
     * working. extend() rather than a fresh singleton() because
     * ArtisanServiceProvider is deferred: it registers its own binding at an
     * unpredictable point and would overwrite a plain rebind, while extenders
     * apply whenever the binding is finally resolved.
     */
    private function registerDevCommand(): void
    {
        $this->app->singleton(DevCommand::class);

        $this->app->extend(
            FrameworkDevCommand::class,
            fn (FrameworkDevCommand $command, Application $app): DevCommand => $app->make(DevCommand::class),
        );
    }

    private function registerConfigObjects(): void
    {
        foreach (self::CONFIG_CLASSES as $configClass) {
            $this->app->singleton($configClass, fn (Application $app) => $configClass::fromRepository($app['config']));
        }
    }

    /**
     * The output seam plus the platform-picked OS terminal-window launcher.
     */
    private function registerTerminal(): void
    {
        $this->app->singleton(Terminal::class, fn () => new Terminal);

        $this->app->singleton(Platform::class, fn () => new Platform);

        $this->app->singleton(TerminalLauncher::class, function (Application $app): TerminalLauncher {
            $platform = $app->make(Platform::class);

            return match (true) {
                $platform->isMacos() => $app->make(MacTerminalLauncher::class),
                $platform->isLinux() => $app->make(LinuxTerminalLauncher::class),
                default => new NullTerminalLauncher,
            };
        });
    }

    /**
     * The process ledger and active-server record survive the
     * app:serve → app:down boundary; the runner feeds them.
     */
    private function registerProcessTracking(): void
    {
        $this->app->singleton(ProcessLedger::class, fn (Application $app) => new ProcessLedger(
            $app->storagePath('framework/boot-up/processes.json'),
        ));

        $this->app->singleton(ActiveServerStore::class, fn (Application $app) => new ActiveServerStore(
            $app->storagePath('framework/boot-up/active-server.json'),
        ));

        $this->app->singleton(ProcessRunner::class, fn (Application $app) => new ProcessRunner(
            processes: $app->make(Factory::class),
            ledger: $app->make(ProcessLedger::class),
            terminal: $app->make(TerminalLauncher::class),
            poller: $app->make(Poller::class),
            logDirectory: $app->storagePath('logs/boot-up'),
            runtimeDirectory: $app->storagePath('framework/boot-up'),
            terminalPidTimeout: $app->make(ProcessConfig::class)->terminalPidTimeout,
        ));

        $this->app->singleton(OutputMultiplexer::class, fn (Application $app) => new OutputMultiplexer(
            processes: $app->make(Factory::class),
            ledger: $app->make(ProcessLedger::class),
        ));
    }

    private function registerHerd(): void
    {
        $this->app->singleton(HerdSites::class, function (Application $app): HerdSites {
            $home = $_SERVER['HOME'] ?? '';

            return new HerdSites($app->make(Platform::class)->isMacos()
                ? "{$home}/Library/Application Support/Herd/config/valet/Sites"
                : "{$home}/.config/valet/Sites");
        });
    }

    /**
     * Readers and writers of the host project's files.
     */
    private function registerProjectFiles(): void
    {
        $this->app->singleton(Composer::class, fn (Application $app) => new Composer(
            processes: $app->make(ProcessRunner::class),
            conflicts: $app->make(LockfileConflictDetector::class),
            basePath: $app->basePath(),
        ));

        $this->app->singleton(EnvFile::class, fn (Application $app) => new EnvFile(
            $app->basePath('.env'),
            $app->basePath('.env.example'),
        ));

        $this->app->singleton(ShellProfile::class, fn () => new ShellProfile);

        $this->app->singleton(GeneratedFilePublisher::class, fn (Application $app) => new GeneratedFilePublisher(
            $app->basePath(),
        ));

        $this->app->singleton(PackageJson::class, fn (Application $app) => new PackageJson(
            $app->basePath('package.json'),
        ));

        $this->app->singleton(ComposerJson::class, fn (Application $app) => new ComposerJson(
            $app->basePath('composer.json'),
        ));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../../config/boot-up.php' => config_path('boot-up.php'),
        ], 'boot-up-config');

        $this->commands([
            // Registered by class as well as through the framework's binding:
            // Artisan resolves `dev` lazily from a class-string map, which has
            // no way to know about the app:serve alias until the command is
            // built. Both routes land on the same singleton.
            DevCommand::class,
            DeployCommand::class,
            DeployScriptCommand::class,
            PipelineCommand::class,
            GenerateGitHooksCommand::class,
            DownCommand::class,
            StatusCommand::class,
        ]);
    }
}
