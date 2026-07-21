# Configuration

Publish the config file to change any of this:

```bash
php artisan vendor:publish --tag=boot-up-config
```

Everything below lives in `config/boot-up.php`. Most values also read an
environment variable, so you can tweak per machine via `.env` without
publishing.

## Environments

| Key            | Default                    | Description                                                                                                                                             |
| -------------- | -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `environments` | `['local', 'development']` | `app:serve` refuses to run when `APP_ENV` (read from the `.env` file) is not in this list. A missing `.env` or `APP_ENV` counts as a fresh local setup. |

## Server

| Key                                  | Env var                        | Default             | Description                                                                                                                                                                                               |
| ------------------------------------ | ------------------------------ | ------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `server.default`                     | `BOOT_UP_SERVER`               | `null`              | `herd`, `sail` or `laravel`. `null` prompts on first run.                                                                                                                                                 |
| `server.prompt`                      | `BOOT_UP_SERVER_PROMPT`        | `true`              | Whether to prompt for a server when none is configured.                                                                                                                                                   |
| `server.drivers`                     | —                              | herd, sail, laravel | Extension point: add your own [`Servers\Server`](EXTENDING.md#custom-servers) implementations.                                                                                                            |
| `server.herd.site`                   | `BOOT_UP_HERD_SITE`            | `null`              | Fixed Herd site name (served at `https://{name}.test`). `null` prompts on first link, defaulting to the project folder name.                                                                              |
| `server.herd.health.attempts`        | `BOOT_UP_HERD_HEALTH_ATTEMPTS` | `10`                | How many times `app:serve` probes the Herd-served site before failing with guidance. It never trusts "Herd started" — it waits for Nginx to actually answer, restarting an unhealthy Herd between checks. |
| `server.herd.health.delay_ms`        | `BOOT_UP_HERD_HEALTH_DELAY_MS` | `500`               | Delay after restarting an unhealthy Herd before re-checking.                                                                                                                                              |
| `server.herd.health.timeout_seconds` | `BOOT_UP_HERD_HEALTH_TIMEOUT`  | `5`                 | Per-request timeout for each reachability check.                                                                                                                                                          |
| `server.artisan.host`                | `BOOT_UP_ARTISAN_HOST`         | `127.0.0.1`         | Where `php artisan serve` binds; also drives the announced URL.                                                                                                                                           |
| `server.artisan.port`                | `BOOT_UP_ARTISAN_PORT`         | `8000`              | Port for `php artisan serve`.                                                                                                                                                                             |

## Tools

| Key                       | Env var                      | Default | Description                                                                                                                               |
| ------------------------- | ---------------------------- | ------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `tools.auto_install`      | `BOOT_UP_TOOLS_AUTO_INSTALL` | `true`  | Install missing tools automatically.                                                                                                      |
| `tools.auto_update`       | `BOOT_UP_TOOLS_AUTO_UPDATE`  | `true`  | Update tools whose version violates your constraint.                                                                                      |
| `tools.required.php`      | `BOOT_UP_PHP_VERSION`        | `*`     | Composer-style constraint, e.g. `^8.3`. `*` accepts any installed version.                                                                |
| `tools.required.node`     | `BOOT_UP_NODE_VERSION`       | `*`     | Same, for Node.                                                                                                                           |
| `tools.required.composer` | `BOOT_UP_COMPOSER_VERSION`   | `*`     | Same, for Composer.                                                                                                                       |
| `tools.installers`        | —                            | `[]`    | Extension point: map a tool id to your own [`Tools\InstallsTool`](EXTENDING.md#custom-tools) class. Wins over built-ins on key collision. |

## Database & migrations

| Key                                   | Env var                   | Default | Description                                                |
| ------------------------------------- | ------------------------- | ------- | ---------------------------------------------------------- |
| `database.create`                     | `BOOT_UP_DB_CREATE`       | `true`  | Create the database when it doesn't exist.                 |
| `database.prompt_missing_credentials` | `BOOT_UP_DB_PROMPT`       | `true`  | Prompt for missing `DB_*` values and write them to `.env`. |
| `migrations.auto`                     | `BOOT_UP_MIGRATIONS_AUTO` | `true`  | Run pending migrations during boot.                        |

## Frontend

| Key                        | Env var                   | Default    | Description                                                                                                                  |
| -------------------------- | ------------------------- | ---------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `frontend.package_manager` | `BOOT_UP_PACKAGE_MANAGER` | `bun`      | `bun`, `yarn`, `npm` or `pnpm`.                                                                                              |
| `frontend.assets`          | `BOOT_UP_ASSETS`          | `watch`    | `watch`, `build` or `skip`.                                                                                                  |
| `frontend.watch_in`        | `BOOT_UP_ASSETS_WATCH_IN` | `terminal` | `terminal` opens the watcher in its own terminal window; `background` runs it detached with logs in `storage/logs/boot-up/`. |

## Queue & services

| Key                          | Env var                    | Default    | Description                                                                    |
| ---------------------------- | -------------------------- | ---------- | ------------------------------------------------------------------------------ |
| `queue.enabled`              | `BOOT_UP_QUEUE`            | `true`     | Start a queue worker (only when `QUEUE_CONNECTION` is not `sync`).             |
| `queue.run_in`               | `BOOT_UP_QUEUE_RUN_IN`     | `terminal` | `terminal` or `background`.                                                    |
| `queue.flags`                | —                          | `[]`       | Extra `queue:work` options, e.g. `['--tries' => 3]`.                           |
| `services.scheduler.enabled` | `BOOT_UP_SCHEDULER`        | `false`    | Start `schedule:work`. Opt-in.                                                 |
| `services.scheduler.run_in`  | `BOOT_UP_SCHEDULER_RUN_IN` | `terminal` | `terminal` or `background`.                                                    |
| `services.horizon.enabled`   | `BOOT_UP_HORIZON`          | `true`     | Start Horizon when `laravel/horizon` is installed (replaces the queue worker). |
| `services.horizon.run_in`    | `BOOT_UP_HORIZON_RUN_IN`   | `terminal` | `terminal` or `background`.                                                    |
| `services.reverb.enabled`    | `BOOT_UP_REVERB`           | `true`     | Start Reverb when `laravel/reverb` is installed.                               |
| `services.reverb.run_in`     | `BOOT_UP_REVERB_RUN_IN`    | `terminal` | `terminal` or `background`.                                                    |

## Deploy

| Key                            | Env var         | Default            | Description                                                                                                            |
| ------------------------------ | --------------- | ------------------ | ---------------------------------------------------------------------------------------------------------------------- |
| `deploy.cache_framework_files` | `BOOT_UP_CACHE` | `false`            | Run the framework cache commands locally. Off by default: `config:cache` breaks `env()` lookups in local development.  |
| `deploy.finalize`              | —               | `['storage:link']` | Artisan commands run at the end of every boot/deploy.                                                                  |
| `deploy.script_generators`     | —               | `[]`               | Extension point: map a platform key to a [`Deploy\Scripts\ScriptGenerator`](EXTENDING.md#custom-deployment-platforms). |

## Pipeline

| Key                   | Default                                                           | Description                                                                                                                                                                                                                          |
| --------------------- | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `pipeline.branches`   | `develop → development`, `staging → staging`, `main → production` | Maps a git branch to the deployment environment whose `DEPLOY_HOOK` secret is called after a green push. Environment names should be unique per branch. With the `none` host the mapping only decides which branches run the checks. |
| `pipeline.generators` | `[]`                                                              | Extension point: map a provider key to a [`Pipelines\PipelineGenerator`](EXTENDING.md#custom-git-providers).                                                                                                                         |
| `pipeline.steps`      | `[]`                                                              | Extra steps injected into the generated pipeline jobs. See [Extending the pipeline](PIPELINES.md#extending-the-pipeline).                                                                                                            |
| `pipeline.files`      | `[]`                                                              | Extra whole files emitted verbatim next to the generated pipeline. See [Extending the pipeline](PIPELINES.md#extending-the-pipeline).                                                                                                |

## Browser, shutdown & misc

| Key                               | Env var                        | Default | Description                                                                                                      |
| --------------------------------- | ------------------------------ | ------- | ---------------------------------------------------------------------------------------------------------------- |
| `browser.open`                    | `BOOT_UP_OPEN_BROWSER`         | `true`  | Open the app in your browser after boot.                                                                         |
| `shutdown.prompt_stop_server`     | `BOOT_UP_SHUTDOWN_PROMPT`      | `true`  | Ask whether to stop the server on `app:down` / Ctrl-C.                                                           |
| `shutdown.stop_server_by_default` | `BOOT_UP_SHUTDOWN_STOP_SERVER` | `false` | The default answer to that prompt. Stopping Herd is machine-wide, so it only ever happens after an explicit yes. |
| `environment.manage_sail_alias`   | `BOOT_UP_MANAGE_SAIL_ALIAS`    | `true`  | Offer (once) to add the `sail` alias to your shell profile.                                                      |

## Pipelines: `serve_steps` and `deploy_steps`

The full `app:serve` and `app:deploy` pipelines are plain arrays of step classes
in the published config. Reorder them, remove steps you don't want, or insert
your own [`Serve\Step`](EXTENDING.md#custom-pipeline-steps) classes anywhere.
