<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Database\Steps\EnsureDatabaseCredentials;
use Igne\LaravelBootstrap\Database\Steps\EnsureDatabaseExists;
use Igne\LaravelBootstrap\Database\Steps\RunPendingMigrations;
use Igne\LaravelBootstrap\Database\Steps\VerifyDatabaseConnection;
use Igne\LaravelBootstrap\Deploy\Steps\CacheFrameworkFiles;
use Igne\LaravelBootstrap\Deploy\Steps\FinalizeApplication;
use Igne\LaravelBootstrap\Deploy\Steps\InstallComposerDependencies;
use Igne\LaravelBootstrap\Deploy\Steps\RunProjectCommands;
use Igne\LaravelBootstrap\Environment\Steps\EnsureEnvFile;
use Igne\LaravelBootstrap\Environment\Steps\EnsureLocalEnvironment;
use Igne\LaravelBootstrap\Environment\Steps\GenerateAppKey;
use Igne\LaravelBootstrap\Frontend\Steps\BuildOrWatchAssets;
use Igne\LaravelBootstrap\Frontend\Steps\InstallFrontendDependencies;
use Igne\LaravelBootstrap\Queue\Steps\StartQueueWorker;
use Igne\LaravelBootstrap\Serve\Steps\AnnounceApplication;
use Igne\LaravelBootstrap\Servers\Artisan\ArtisanServer;
use Igne\LaravelBootstrap\Servers\Herd\HerdServer;
use Igne\LaravelBootstrap\Servers\Sail\SailServer;
use Igne\LaravelBootstrap\Servers\Steps\StartServer;
use Igne\LaravelBootstrap\Tools\Steps\EnsureToolsReady;

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed environments
    |--------------------------------------------------------------------------
    | app:serve refuses to run when APP_ENV (read from the .env file) is not
    | in this list. A missing .env or APP_ENV counts as a fresh local setup.
    */
    'environments' => ['local', 'development'],

    /*
    |--------------------------------------------------------------------------
    | Development server
    |--------------------------------------------------------------------------
    | Extension point: add your own driver under 'drivers' with a string key
    | and a class implementing Igne\LaravelBootstrap\Servers\Server.
    */
    'server' => [
        'default' => env('BOOTSTRAP_SERVER'),
        'prompt' => env('BOOTSTRAP_SERVER_PROMPT', true),
        'drivers' => [
            'herd' => HerdServer::class,
            'sail' => SailServer::class,
            'laravel' => ArtisanServer::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Development tools
    |--------------------------------------------------------------------------
    | 'required' maps a tool id to a composer-style version constraint
    | ('*' = any installed version). Missing tools are installed, tools whose
    | version violates the constraint are updated. Extension point:
    | 'installers' maps ids to classes implementing Tools\InstallsTool and
    | overrides built-ins on key collision.
    */
    'tools' => [
        'auto_install' => env('BOOTSTRAP_TOOLS_AUTO_INSTALL', true),
        'auto_update' => env('BOOTSTRAP_TOOLS_AUTO_UPDATE', true),
        'required' => [
            'php' => env('BOOTSTRAP_PHP_VERSION', '*'),
            'node' => env('BOOTSTRAP_NODE_VERSION', '*'),
            'composer' => env('BOOTSTRAP_COMPOSER_VERSION', '*'),
        ],
        'installers' => [],
    ],

    'database' => [
        'create' => env('BOOTSTRAP_DB_CREATE', true),
        'prompt_missing_credentials' => env('BOOTSTRAP_DB_PROMPT', true),
    ],

    'migrations' => [
        'auto' => env('BOOTSTRAP_MIGRATIONS_AUTO', true),
    ],

    'frontend' => [
        'package_manager' => env('BOOTSTRAP_PACKAGE_MANAGER', 'bun'), // bun | yarn | npm | pnpm
        'assets' => env('BOOTSTRAP_ASSETS', 'watch'), // watch | build | skip
        'watch_in' => env('BOOTSTRAP_ASSETS_WATCH_IN', 'background'), // background | terminal
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue worker
    |--------------------------------------------------------------------------
    | A worker only starts when QUEUE_CONNECTION is not "sync". The connection
    | itself comes from the application's .env, not from this file.
    */
    'queue' => [
        'enabled' => env('BOOTSTRAP_QUEUE', true),
        'run_in' => env('BOOTSTRAP_QUEUE_RUN_IN', 'background'), // background | terminal
        'flags' => [],
    ],

    'deploy' => [
        // config:cache breaks env() lookups in local development — off by default.
        'cache_framework_files' => env('BOOTSTRAP_CACHE', false),
        // Artisan commands run at the end of every boot/deploy.
        'finalize' => ['storage:link'],
        // Extension point for app:deploy-script: 'platform' => class implementing
        // Igne\LaravelBootstrap\Deploy\Scripts\ScriptGenerator (wins over built-ins).
        'script_generators' => [],
    ],

    'browser' => [
        'open' => env('BOOTSTRAP_OPEN_BROWSER', true),
    ],

    'shutdown' => [
        'prompt_stop_server' => env('BOOTSTRAP_SHUTDOWN_PROMPT', true),
        'stop_server_by_default' => env('BOOTSTRAP_SHUTDOWN_STOP_SERVER', false),
    ],

    'environment' => [
        'manage_sail_alias' => env('BOOTSTRAP_MANAGE_SAIL_ALIAS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | The app:serve pipeline
    |--------------------------------------------------------------------------
    | The full boot, in order. Insert your own Serve\Step classes anywhere,
    | remove steps you do not want, or reorder them.
    */
    'serve_steps' => [
        EnsureEnvFile::class,
        EnsureLocalEnvironment::class,
        GenerateAppKey::class,
        EnsureToolsReady::class,
        StartServer::class,
        InstallComposerDependencies::class,
        InstallFrontendDependencies::class,
        EnsureDatabaseCredentials::class,
        EnsureDatabaseExists::class,
        VerifyDatabaseConnection::class,
        RunProjectCommands::class.':before',
        RunPendingMigrations::class,
        RunProjectCommands::class.':after',
        CacheFrameworkFiles::class,
        FinalizeApplication::class,
        StartQueueWorker::class,
        BuildOrWatchAssets::class,
        AnnounceApplication::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | The app:deploy pipeline
    |--------------------------------------------------------------------------
    | The deploy-only subset: no server, no queue worker, no browser.
    */
    'deploy_steps' => [
        EnsureEnvFile::class,
        EnsureLocalEnvironment::class,
        GenerateAppKey::class,
        InstallComposerDependencies::class,
        InstallFrontendDependencies::class,
        RunProjectCommands::class.':before',
        RunPendingMigrations::class,
        RunProjectCommands::class.':after',
        CacheFrameworkFiles::class,
        FinalizeApplication::class,
    ],
];
