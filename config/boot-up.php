<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Database\Steps\EnsureDatabaseCredentials;
use Igne\LaravelBootUp\Database\Steps\EnsureDatabaseExists;
use Igne\LaravelBootUp\Database\Steps\RunPendingMigrations;
use Igne\LaravelBootUp\Database\Steps\VerifyDatabaseConnection;
use Igne\LaravelBootUp\Deploy\Steps\CacheFrameworkFiles;
use Igne\LaravelBootUp\Deploy\Steps\FinalizeApplication;
use Igne\LaravelBootUp\Deploy\Steps\InstallComposerDependencies;
use Igne\LaravelBootUp\Deploy\Steps\RunDeployTasks;
use Igne\LaravelBootUp\Environment\Steps\EnsureEnvFile;
use Igne\LaravelBootUp\Environment\Steps\EnsureLocalEnvironment;
use Igne\LaravelBootUp\Environment\Steps\GenerateAppKey;
use Igne\LaravelBootUp\Frontend\Steps\BuildOrWatchAssets;
use Igne\LaravelBootUp\Frontend\Steps\InstallFrontendDependencies;
use Igne\LaravelBootUp\Queue\Steps\StartQueueWorker;
use Igne\LaravelBootUp\Serve\Steps\AnnounceApplication;
use Igne\LaravelBootUp\Servers\Artisan\ArtisanServer;
use Igne\LaravelBootUp\Servers\Herd\HerdServer;
use Igne\LaravelBootUp\Servers\Sail\SailServer;
use Igne\LaravelBootUp\Servers\Steps\StartServer;
use Igne\LaravelBootUp\Tools\Steps\EnsureToolsReady;
use Igne\LaravelBootUp\Workers\Steps\StartHorizon;
use Igne\LaravelBootUp\Workers\Steps\StartReverb;
use Igne\LaravelBootUp\Workers\Steps\StartScheduler;

return [

    /*
    |--------------------------------------------------------------------------
    | Environment guard
    |--------------------------------------------------------------------------
    | app:serve refuses to run when APP_ENV (read from the .env file) is not
    | in 'allowed'. A missing .env or APP_ENV counts as a fresh local setup.
    */
    'environment' => [
        'allowed' => ['local', 'development'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Development server
    |--------------------------------------------------------------------------
    | Extension point: add your own driver under 'drivers' with a string key
    | and a class implementing Igne\LaravelBootUp\Contracts\Server. Driver
    | settings live in their own sections below ('herd', 'artisan', 'sail').
    */
    'server' => [
        'default' => env('BOOT_UP_SERVER'),
        'prompt' => env('BOOT_UP_SERVER_PROMPT', true),
        'drivers' => [
            'herd' => HerdServer::class,
            'sail' => SailServer::class,
            'laravel' => ArtisanServer::class,
        ],
    ],

    'herd' => [
        // Fixed Herd site name (served at https://{name}.test). null
        // prompts on first link, defaulting to the project folder name.
        'site' => env('BOOT_UP_HERD_SITE'),

        // app:serve does not trust "Herd started" — it verifies Nginx
        // actually answers the served site. A running Herd is never
        // restarted (only a down one, once, halfway through the checks),
        // so a healthy Herd is never disrupted. 'attempts' bounds the
        // checks (a permanently broken Herd fails fast with guidance
        // rather than hanging); 'delay_ms' waits between checks;
        // 'timeout_seconds' caps each reachability request.
        'health' => [
            'attempts' => (int) env('BOOT_UP_HERD_HEALTH_ATTEMPTS', 10),
            'delay_ms' => (int) env('BOOT_UP_HERD_HEALTH_DELAY_MS', 500),
            'timeout_seconds' => (int) env('BOOT_UP_HERD_HEALTH_TIMEOUT', 5),
        ],
    ],

    'artisan' => [
        // Where `php artisan serve` binds; also drives the announced URL.
        'host' => env('BOOT_UP_ARTISAN_HOST', '127.0.0.1'),
        'port' => (int) env('BOOT_UP_ARTISAN_PORT', 8000),
    ],

    'sail' => [
        // Offer to add the conventional `sail` alias to the shell profile.
        'manage_alias' => env('BOOT_UP_SAIL_MANAGE_ALIAS', true),

        // How long `sail up` may take before its containers report running.
        'ready_timeout_seconds' => (int) env('BOOT_UP_SAIL_READY_TIMEOUT', 120),

        'docker' => [
            // How long a cold Docker daemon may take to come up.
            'start_timeout_seconds' => (int) env('BOOT_UP_SAIL_DOCKER_START_TIMEOUT', 60),
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
        'auto_install' => env('BOOT_UP_TOOLS_AUTO_INSTALL', true),
        'auto_update' => env('BOOT_UP_TOOLS_AUTO_UPDATE', true),
        'required' => [
            'php' => env('BOOT_UP_PHP_VERSION', '*'),
            'node' => env('BOOT_UP_NODE_VERSION', '*'),
            'composer' => env('BOOT_UP_COMPOSER_VERSION', '*'),
        ],
        'installers' => [],
    ],

    'database' => [
        'create' => env('BOOT_UP_DB_CREATE', true),
        'prompt_missing_credentials' => env('BOOT_UP_DB_PROMPT', true),
        // Detect DB_* values left behind by another server (e.g. Sail's
        // `mysql` host after `sail:install`) and offer to fix them for the
        // server that drives this run.
        'reconcile_credentials' => env('BOOT_UP_DB_RECONCILE', true),
        'migrations' => [
            'auto' => env('BOOT_UP_MIGRATIONS_AUTO', true),
        ],
    ],

    'frontend' => [
        'package_manager' => env('BOOT_UP_PACKAGE_MANAGER', 'bun'), // bun | yarn | npm | pnpm
        'assets' => env('BOOT_UP_ASSETS', 'watch'), // watch | build | skip
        'watch_in' => env('BOOT_UP_ASSETS_WATCH_IN', 'combined'), // combined | terminal | background
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue worker
    |--------------------------------------------------------------------------
    | A worker only starts when QUEUE_CONNECTION is not "sync". The connection
    | itself comes from the application's .env, not from this file.
    */
    'queue' => [
        'enabled' => env('BOOT_UP_QUEUE', true),
        'run_in' => env('BOOT_UP_QUEUE_RUN_IN', 'combined'), // combined | terminal | background
        'flags' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Long-running services
    |--------------------------------------------------------------------------
    | Horizon and Reverb start automatically when the matching Laravel
    | package is installed (and replace/extend the queue worker where it
    | applies). The scheduler is opt-in: schedule:work on a project with
    | no scheduled tasks is pure noise.
    |
    | run_in: 'combined' streams the worker into the app:serve terminal with
    | a colored [name] prefix (Ctrl+C stops everything), 'terminal' opens its
    | own terminal window, 'background' runs it detached with logs in
    | storage/logs/boot-up/.
    */
    'horizon' => [
        'enabled' => env('BOOT_UP_HORIZON', true),
        'run_in' => env('BOOT_UP_HORIZON_RUN_IN', 'combined'), // combined | terminal | background
    ],

    'reverb' => [
        'enabled' => env('BOOT_UP_REVERB', true),
        'run_in' => env('BOOT_UP_REVERB_RUN_IN', 'combined'), // combined | terminal | background
    ],

    'scheduler' => [
        'enabled' => env('BOOT_UP_SCHEDULER', false),
        'run_in' => env('BOOT_UP_SCHEDULER_RUN_IN', 'combined'), // combined | terminal | background
    ],

    /*
    |--------------------------------------------------------------------------
    | Background processes
    |--------------------------------------------------------------------------
    | Services launched in their own terminal window (run_in => 'terminal')
    | report their PID back through a pid file. A cold Terminal.app or a heavy
    | shell startup profile (nvm, oh-my-zsh, ...) can delay that; this bounds
    | the wait in seconds before boot-up recovers the PID from the process
    | table or, failing that, restarts the process in the background — it never
    | aborts the boot.
    */
    'process' => [
        'terminal_pid_timeout' => (int) env('BOOT_UP_TERMINAL_PID_TIMEOUT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shutdown behaviour
    |--------------------------------------------------------------------------
    | Whether teardown (Ctrl+C on app:serve, app:down) asks before stopping
    | the development server, and what the unattended answer is.
    */
    'shutdown' => [
        'prompt_stop_server' => env('BOOT_UP_SHUTDOWN_PROMPT', true),
        'stop_server_by_default' => env('BOOT_UP_SHUTDOWN_STOP_SERVER', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | app:serve
    |--------------------------------------------------------------------------
    | The full boot, in order. Insert your own Contracts\Step classes anywhere,
    | remove steps you do not want, or reorder them. 'auto_accept' (or --yes)
    | skips the confirmation prompt; 'open_browser' opens the served URL when
    | the boot completes.
    */
    'serve' => [
        'open_browser' => env('BOOT_UP_OPEN_BROWSER', true),
        'auto_accept' => env('BOOT_UP_SERVE_AUTO_ACCEPT', false),
        'steps' => [
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
            RunDeployTasks::class.':before',
            RunPendingMigrations::class,
            RunDeployTasks::class.':after',
            CacheFrameworkFiles::class,
            FinalizeApplication::class,
            StartQueueWorker::class,
            StartHorizon::class,
            StartReverb::class,
            StartScheduler::class,
            BuildOrWatchAssets::class,
            AnnounceApplication::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | app:deploy and deploy scripts
    |--------------------------------------------------------------------------
    | 'steps' is the deploy-only pipeline subset: no server, no queue worker,
    | no browser. 'finalize' lists artisan commands run at the end of every
    | boot/deploy. Extension point for generate:deploy-script:
    | 'script_generators' maps 'platform' => class implementing
    | Igne\LaravelBootUp\Contracts\ScriptGenerator (wins over built-ins).
    */
    'deploy' => [
        // config:cache breaks env() lookups in local development — off by default.
        'cache_framework_files' => env('BOOT_UP_CACHE', false),
        'finalize' => ['storage:link'],
        'script_generators' => [],
        'auto_accept' => env('BOOT_UP_DEPLOY_AUTO_ACCEPT', false),
        'steps' => [
            EnsureEnvFile::class,
            EnsureLocalEnvironment::class,
            GenerateAppKey::class,
            InstallComposerDependencies::class,
            InstallFrontendDependencies::class,
            RunDeployTasks::class.':before',
            RunPendingMigrations::class,
            RunDeployTasks::class.':after',
            CacheFrameworkFiles::class,
            FinalizeApplication::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | CI/CD pipelines
    |--------------------------------------------------------------------------
    | generate:pipeline generates a provider pipeline, shared scripts/ci/*.sh and
    | .env.pipeline. 'branches' maps a git branch to the deployment environment
    | (GitHub environment / Bitbucket deployment) whose DEPLOY_HOOK secret is
    | curled after a green push (an unset secret skips that deploy gracefully).
    | Environment names should be unique per branch.
    | Extension point: 'generators' maps a provider key to a class implementing
    | Igne\LaravelBootUp\Contracts\PipelineGenerator (wins over built-ins).
    */
    'pipeline' => [
        'branches' => [
            'develop' => 'development',
            'staging' => 'staging',
            'main' => 'production',
        ],
        'generators' => [],

        // A COMPOSER_AUTH secret authenticates composer against private or
        // licensed registries (Laravel Nova, a private Satis, ...). Leave null
        // to auto-detect (on when laravel/nova is required), or force it:
        //   true  — always wire COMPOSER_AUTH into the pipeline
        //   false — never offer it, even with Nova installed
        'composer_auth' => env('BOOT_UP_PIPELINE_COMPOSER_AUTH'),

        // Extra steps injected into the generated pipeline jobs. Each is
        // spliced into its 'job' anchor (lint/build/test/deploy) 'before' or
        // 'after' that job's own step. Reruns are idempotent — the pipeline is
        // regenerated from this config, so nothing is ever duplicated.
        //   'id'       unique identifier (required)
        //   'job'      anchor: lint | build | test | deploy (required)
        //   'position' before | after (required)
        //   'run'      the command to run (required)
        //   'name'     display name (optional; defaults to the id)
        //   'provider' restrict to one provider key (optional; default: all)
        //   'env'      GitHub only — map of name => value (optional)
        'steps' => [
            // [
            //     'id' => 'notify-slack',
            //     'job' => 'test',
            //     'position' => 'after',
            //     'name' => 'Notify Slack',
            //     'run' => 'bash scripts/ci/notify.sh',
            //     'env' => ['WEBHOOK' => '${{ secrets.SLACK_WEBHOOK }}'],
            // ],
        ],

        // Extra whole files emitted verbatim next to the generated pipeline.
        // Give each a relative 'path' and exactly one of 'contents' (inline)
        // or 'stub' (a file read verbatim, relative to the project root).
        //   'executable' chmod 0755 the written file (optional)
        //   'provider'   restrict to one provider key (optional; default: all)
        'files' => [
            // [
            //     'path' => '.github/workflows/nightly.yml',
            //     'stub' => 'stubs/nightly.yml',
            //     'provider' => 'github',
            // ],
        ],
    ],
];
