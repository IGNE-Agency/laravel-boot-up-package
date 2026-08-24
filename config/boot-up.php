<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Boot\Steps\AnnounceApplication;
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
use Igne\LaravelBootUp\Frontend\Steps\BuildAssets;
use Igne\LaravelBootUp\Frontend\Steps\InstallFrontendDependencies;
use Igne\LaravelBootUp\Servers\Steps\StartServer;
use Igne\LaravelBootUp\Tools\Steps\EnsureToolsReady;

return [

    /*
    |--------------------------------------------------------------------------
    | Environment guard
    |--------------------------------------------------------------------------
    | Both app:setup and php artisan dev refuse to run when APP_ENV (read from
    | the .env file) is not in 'allowed'. A missing .env or APP_ENV counts as a
    | fresh local setup.
    */
    'environment' => [
        'allowed' => ['local', 'development'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Development server
    |--------------------------------------------------------------------------
    | The 'herd', 'sail' and 'artisan' drivers are always available; their
    | settings live in their own sections below. Extension point: add your own
    | under 'drivers' with a string key and a class implementing
    | Igne\LaravelBootUp\Contracts\Server, e.g.
    |
    |     'drivers' => ['valet' => \App\BootUp\ValetServer::class],
    |
    | Entries merge over the built-ins, so a key of 'herd' replaces that driver.
    */
    'server' => [
        'default' => env('BOOT_UP_SERVER'),
        'prompt' => env('BOOT_UP_SERVER_PROMPT', true),
        'drivers' => [],
    ],

    'herd' => [
        // Fixed Herd site name (served at https://{name}.test). null
        // prompts on first link, defaulting to the project folder name.
        'site' => env('BOOT_UP_HERD_SITE'),

        // boot-up does not trust "Herd started" — it verifies Nginx
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
        // bun | yarn | npm | pnpm — leave unset to detect from the lockfile
        // (package.json's please-use-* engines sentinel always wins).
        'package_manager' => env('BOOT_UP_PACKAGE_MANAGER'),
        'assets' => env('BOOT_UP_ASSETS', 'watch'), // watch | build | skip
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
    | Each of these runs as a dev process: `php artisan dev` gives them a tab
    | each in one terminal, and `php artisan dev --detach` runs them in the
    | background with logs in storage/logs/boot-up/.
    */
    'horizon' => [
        'enabled' => env('BOOT_UP_HORIZON', true),
    ],

    'reverb' => [
        'enabled' => env('BOOT_UP_REVERB', true),
    ],

    'scheduler' => [
        'enabled' => env('BOOT_UP_SCHEDULER', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Processes
    |--------------------------------------------------------------------------
    | Teardown signals a process with TERM and waits 'term_grace_seconds' for
    | it to go; if it is still there it gets KILL and 'kill_grace_seconds'.
    | A process that survives both stays in the ledger with a warning rather
    | than being forgotten while it is still running.
    |
    | 'install_timeout_seconds' is the ceiling for installing dependencies,
    | which takes minutes on a slow network or a large project -- well clear of
    | the per-command timeout meant for quick commands.
    */
    'process' => [
        'term_grace_seconds' => (int) env('BOOT_UP_TERM_GRACE', 5),
        'kill_grace_seconds' => (int) env('BOOT_UP_KILL_GRACE', 2),
        'install_timeout_seconds' => (int) env('BOOT_UP_INSTALL_TIMEOUT', 1800),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shutdown behaviour
    |--------------------------------------------------------------------------
    | Whether teardown (Ctrl+C during app:setup, or app:down) asks before
    | stopping the development server, and what the unattended answer is.
    */
    'shutdown' => [
        'prompt_stop_server' => env('BOOT_UP_SHUTDOWN_PROMPT', true),
        'stop_server_by_default' => env('BOOT_UP_SHUTDOWN_STOP_SERVER', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | php artisan app:setup
    |--------------------------------------------------------------------------
    | 'steps' is the boot pipeline: the sequential work that has to finish
    | before the application can run, in order. Insert your own Contracts\Step
    | classes anywhere, remove steps you do not want, or reorder them.
    |
    | 'auto_accept' (or --yes) skips the confirmation prompt; 'open_browser'
    | opens the served URL when the setup completes.
    */
    'setup' => [
        'open_browser' => env('BOOT_UP_OPEN_BROWSER', true),
        'auto_accept' => env('BOOT_UP_SETUP_AUTO_ACCEPT', false),
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
            BuildAssets::class,
            AnnounceApplication::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | php artisan dev
    |--------------------------------------------------------------------------
    | The long-running processes are not steps: `php artisan dev` is Laravel's
    | own dev command, and boot-up only decides which processes this project
    | can use and rewrites each one for the server app:setup left serving it.
    | Run app:setup first -- dev says so if the project is not ready.
    |
    | 'logs' keeps Laravel's log-tailing process, which needs laravel/pail.
    | Register your own processes from any service provider:
    |
    |     DevCommands::register('stripe listen --forward-to '.config('app.url'));
    */
    'dev' => [
        'logs' => env('BOOT_UP_DEV_LOGS', true),
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
