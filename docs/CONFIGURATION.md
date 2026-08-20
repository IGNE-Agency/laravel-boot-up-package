# Configuration

Publish the config file to change any of this:

```bash
php artisan vendor:publish --tag=boot-up-config
```

Everything below lives in `config/boot-up.php`. Most values also read an
environment variable, so you can tweak per machine via `.env` without
publishing. Each section below maps to exactly one top-level config key —
and to exactly one config class inside the package.

## Environment

Which `APP_ENV` values the boot may run under.

| Key                   | Default                    | Description                                                                                                                                             |
| --------------------- | -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `environment.allowed` | `['local', 'development']` | `php artisan dev` refuses to run when `APP_ENV` (read from the `.env` file) is not in this list. A missing `.env` or `APP_ENV` counts as a fresh local setup. |

## Server

Which development server drives `php artisan dev`. Driver-specific settings live
in their own sections: [Herd](#herd), [Artisan](#artisan), [Sail](#sail).

| Key              | Env var                 | Default             | Description                                                                                      |
| ---------------- | ----------------------- | ------------------- | ------------------------------------------------------------------------------------------------ |
| `server.default` | `BOOT_UP_SERVER`        | `null`              | `herd`, `sail` or `artisan`. `null` prompts on first run.                                        |
| `server.prompt`  | `BOOT_UP_SERVER_PROMPT` | `true`              | Whether to prompt for a server when none is configured.                                          |
| `server.drivers` | —                       | `[]`                | Extension point: add your own [`Contracts\Server`](EXTENDING.md#custom-servers) implementations. `herd`, `sail` and `artisan` are always available; an entry reusing one of those keys replaces that driver. A class that is not a `Server` is rejected when it is selected. |

## Herd

| Key                           | Env var                        | Default | Description                                                                                                                                                                              |
| ----------------------------- | ------------------------------ | ------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `herd.site`                   | `BOOT_UP_HERD_SITE`            | `null`  | Fixed Herd site name (served at `https://{name}.test`). `null` prompts on first link, defaulting to the project folder name.                                                             |
| `herd.health.attempts`        | `BOOT_UP_HERD_HEALTH_ATTEMPTS` | `10`    | How many times the boot probes the Herd-served site before failing. It waits for Nginx to actually answer; a running Herd is never restarted, only a down one, once, at the midpoint. |
| `herd.health.delay_ms`        | `BOOT_UP_HERD_HEALTH_DELAY_MS` | `500`   | Delay between reachability checks.                                                                                                                                                       |
| `herd.health.timeout_seconds` | `BOOT_UP_HERD_HEALTH_TIMEOUT`  | `5`     | Per-request timeout for each reachability check.                                                                                                                                         |

## Artisan

Settings for the `php artisan serve` driver.

| Key            | Env var                | Default     | Description                                                     |
| -------------- | ---------------------- | ----------- | --------------------------------------------------------------- |
| `artisan.host` | `BOOT_UP_ARTISAN_HOST` | `127.0.0.1` | Where `php artisan serve` binds; also drives the announced URL. |
| `artisan.port` | `BOOT_UP_ARTISAN_PORT` | `8000`      | Port for `php artisan serve`.                                   |

## Sail

| Key                                 | Env var                             | Default | Description                                                       |
| ----------------------------------- | ----------------------------------- | ------- | ----------------------------------------------------------------- |
| `sail.manage_alias`                 | `BOOT_UP_SAIL_MANAGE_ALIAS`         | `true`  | Offer (once) to add the `sail` alias to your shell profile.       |
| `sail.ready_timeout_seconds`        | `BOOT_UP_SAIL_READY_TIMEOUT`        | `120`   | How long `sail up` may take before its containers report running. |
| `sail.docker.start_timeout_seconds` | `BOOT_UP_SAIL_DOCKER_START_TIMEOUT` | `60`    | How long a cold Docker daemon may take to come up.                |

## Tools

| Key                       | Env var                      | Default | Description                                                                                                                                   |
| ------------------------- | ---------------------------- | ------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `tools.auto_install`      | `BOOT_UP_TOOLS_AUTO_INSTALL` | `true`  | Install missing tools automatically.                                                                                                          |
| `tools.auto_update`       | `BOOT_UP_TOOLS_AUTO_UPDATE`  | `true`  | Update tools whose version violates your constraint.                                                                                          |
| `tools.required.php`      | `BOOT_UP_PHP_VERSION`        | `*`     | Composer-style constraint, e.g. `^8.3`. `*` accepts any installed version.                                                                    |
| `tools.required.node`     | `BOOT_UP_NODE_VERSION`       | `*`     | Same, for Node.                                                                                                                               |
| `tools.required.composer` | `BOOT_UP_COMPOSER_VERSION`   | `*`     | Same, for Composer.                                                                                                                           |
| `tools.installers`        | —                            | `[]`    | Extension point: map a tool id to your own [`Contracts\InstallsTool`](EXTENDING.md#custom-tools) class. Wins over built-ins on key collision. |

## Database

| Key                                   | Env var                   | Default | Description                                                |
| ------------------------------------- | ------------------------- | ------- | ---------------------------------------------------------- |
| `database.create`                     | `BOOT_UP_DB_CREATE`       | `true`  | Create the database when it doesn't exist.                 |
| `database.prompt_missing_credentials` | `BOOT_UP_DB_PROMPT`       | `true`  | Prompt for missing `DB_*` values and write them to `.env`. |
| `database.reconcile_credentials`      | `BOOT_UP_DB_RECONCILE`    | `true`  | Offer to fix `DB_*` values another server left behind.     |
| `database.migrations.auto`            | `BOOT_UP_MIGRATIONS_AUTO` | `true`  | Run pending migrations during boot.                        |

## Frontend

| Key                        | Env var                   | Default    | Description                                                                      |
| -------------------------- | ------------------------- | ---------- | -------------------------------------------------------------------------------- |
| `frontend.package_manager` | `BOOT_UP_PACKAGE_MANAGER` | unset      | `bun`, `yarn`, `npm` or `pnpm` — see the selection chain below.                  |
| `frontend.assets`          | `BOOT_UP_ASSETS`          | `watch`    | `watch`, `build` or `skip`. `watch` runs the watcher as a dev process.           |

The package manager is selected by the strongest signal available, in order:

1. A `please-use-{manager}` sentinel in `package.json`'s `engines` (always
   wins — it's the project pinning itself; a warning names the conflict when
   it overrides an explicit config value).
2. The `frontend.package_manager` config value / `BOOT_UP_PACKAGE_MANAGER`.
3. The lockfile on disk (`bun.lock`, `yarn.lock`, `package-lock.json`,
   `pnpm-lock.yaml`), announced with a dim note.
4. `bun`, the default.

## Queue & workers

These all run as dev processes: `php artisan dev` gives each one a tab in
Laravel's dev terminal, and `php artisan dev --detach` runs them in the
background with logs in `storage/logs/boot-up/`.

The keys below decide whether a process runs at all. Which command it runs,
and whether the application or another package overrides it, is covered in
[Dev processes](EXTENDING.md#dev-processes).

### Queue

| Key             | Env var                | Default    | Description                                                        |
| --------------- | ---------------------- | ---------- | ------------------------------------------------------------------ |
| `queue.enabled` | `BOOT_UP_QUEUE`        | `true`     | Start a queue worker (only when `QUEUE_CONNECTION` is not `sync`). |
| `queue.flags`   | —                      | `[]`       | Extra `queue:work` options, e.g. `['--tries' => 3]`.               |

### Horizon

| Key               | Env var                  | Default    | Description                                                                    |
| ----------------- | ------------------------ | ---------- | ------------------------------------------------------------------------------ |
| `horizon.enabled` | `BOOT_UP_HORIZON`        | `true`     | Start Horizon when `laravel/horizon` is installed (replaces the queue worker). |

### Reverb

| Key              | Env var                 | Default    | Description                                      |
| ---------------- | ----------------------- | ---------- | ------------------------------------------------ |
| `reverb.enabled` | `BOOT_UP_REVERB`        | `true`     | Start Reverb when `laravel/reverb` is installed. |

### Scheduler

| Key                 | Env var                    | Default    | Description                             |
| ------------------- | -------------------------- | ---------- | --------------------------------------- |
| `scheduler.enabled` | `BOOT_UP_SCHEDULER`        | `false`    | Start `schedule:work`. Opt-in.          |

## Process

| Key                              | Env var                    | Default | Description                                                                                                          |
| -------------------------------- | -------------------------- | ------- | -------------------------------------------------------------------------------------------------------------------- |
| `process.term_grace_seconds`     | `BOOT_UP_TERM_GRACE`       | `5`     | How long a process may take to honour `TERM` before it gets `KILL`. `0` signals and moves on.                        |
| `process.kill_grace_seconds`     | `BOOT_UP_KILL_GRACE`       | `2`     | How long it may take to disappear after `KILL`. One that survives both stays in the ledger with a warning.           |
| `process.install_timeout_seconds`| `BOOT_UP_INSTALL_TIMEOUT`  | `1800`  | Ceiling for installing Composer or frontend dependencies, which takes minutes on a slow network or a large project.  |

## Shutdown

| Key                               | Env var                        | Default | Description                                                                                                      |
| --------------------------------- | ------------------------------ | ------- | ---------------------------------------------------------------------------------------------------------------- |
| `shutdown.prompt_stop_server`     | `BOOT_UP_SHUTDOWN_PROMPT`      | `true`  | Ask whether to stop the server on `app:down` / Ctrl-C.                                                           |
| `shutdown.stop_server_by_default` | `BOOT_UP_SHUTDOWN_STOP_SERVER` | `false` | The default answer to that prompt. Stopping Herd is machine-wide, so it only ever happens after an explicit yes. |

## Dev

| Key                  | Env var                     | Default  | Description                                                                          |
| -------------------- | --------------------------- | -------- | ------------------------------------------------------------------------------------ |
| `dev.open_browser` | `BOOT_UP_OPEN_BROWSER`      | `true`   | Open the app in your browser after boot.                                             |
| `dev.auto_accept`  | `BOOT_UP_DEV_AUTO_ACCEPT` | `false`  | Skip the "What dev will do — continue?" prompt. `--yes` does the same per run.       |
| `dev.steps`          | —                           | 17 steps | The full boot pipeline, in order — see [Step pipelines](#step-pipelines).            |
| `dev.logs`           | `BOOT_UP_DEV_LOGS`          | `true`   | Keep Laravel's `logs` process, which tails the log with Pail. Dropped without Pail.  |

## Deploy

| Key                            | Env var                      | Default            | Description                                                                                                           |
| ------------------------------ | ---------------------------- | ------------------ | --------------------------------------------------------------------------------------------------------------------- |
| `deploy.cache_framework_files` | `BOOT_UP_CACHE`              | `false`            | Run the framework cache commands locally. Off by default: `config:cache` breaks `env()` lookups in local development. |
| `deploy.finalize`              | —                            | `['storage:link']` | Artisan commands run at the end of every boot/deploy.                                                                 |
| `deploy.script_generators`     | —                            | `[]`               | Extension point: map a platform key to a [`Contracts\ScriptGenerator`](EXTENDING.md#custom-deployment-platforms).     |
| `deploy.auto_accept`           | `BOOT_UP_DEPLOY_AUTO_ACCEPT` | `false`            | Skip the confirmation prompt for `app:deploy` — independent of `dev.auto_accept`.                                   |
| `deploy.steps`                 | —                            | 10 steps           | The deploy-only pipeline subset: no server, no dev processes, no browser — see [Step pipelines](#step-pipelines).     |

## Pipeline

| Key                      | Default                                                           | Description                                                                                                                                                                                                                                                 |
| ------------------------ | ----------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `pipeline.branches`      | `develop → development`, `staging → staging`, `main → production` | Maps a git branch to the deployment environment whose `DEPLOY_HOOK` secret is called after a green push. Environment names should be unique per branch. With the `none` host the mapping only decides which branches run the checks.                        |
| `pipeline.generators`    | `[]`                                                              | Extension point: map a provider key to a [`Contracts\PipelineGenerator`](EXTENDING.md#custom-git-providers).                                                                                                                                                |
| `pipeline.composer_auth` | `null`                                                            | Whether the pipeline gets a `COMPOSER_AUTH` secret to authenticate composer against a private/licensed registry (Nova, a private Satis, ...). `null` auto-detects (on with `laravel/nova`); `true`/`false` force it. Env: `BOOT_UP_PIPELINE_COMPOSER_AUTH`. |
| `pipeline.steps`         | `[]`                                                              | Extra steps injected into the generated pipeline jobs. See [Extending the pipeline](PIPELINES.md#extending-the-pipeline).                                                                                                                                   |
| `pipeline.files`         | `[]`                                                              | Extra whole files emitted verbatim next to the generated pipeline. See [Extending the pipeline](PIPELINES.md#extending-the-pipeline).                                                                                                                       |

## When configuration is wrong

Every check names the key it came from, because a misconfiguration you cannot
locate is barely better than one that fails silently.

The two step pipelines are validated **before the plan is printed**: an entry
naming a class that does not exist, or one that is not a `Contracts\Step`, stops
the run there. That matters because the alternative is failing partway through
the pipeline, after the plan was confirmed and after earlier steps may already
have written `.env` or run migrations.

The driver, installer and generator maps are validated **where they resolve** —
selecting a server, asking for a tool's installer, listing the generators.
Nothing has been changed by that point, so an unrelated command does not need to
fail over a key it never reads.

Numbers are range-checked rather than accepted and worked around: a
`herd.health.attempts` of `0` used to report "unreachable after 0 attempt(s)"
without probing once, and a negative `herd.health.delay_ms` crashed mid-boot
inside `usleep()`.

An enum key given something that is not a string — an array, or the bool that
`env('BOOT_UP_ASSETS', false)` produces — is reported as that type. Leaving such
a key unset, or setting it to an empty string, still means "use the default".

## Step pipelines

The boot pipeline (`dev.steps`) and the `app:deploy` pipeline
(`deploy.steps`) are plain arrays of step classes in the published config.
Reorder them, remove steps you don't want, or insert your own
[`Contracts\Step`](EXTENDING.md#custom-pipeline-steps) classes anywhere.

Steps do the sequential work that has to finish before the application can
run: the `.env`, the tools, the server, the database, migrations, the asset
build. The long-running processes are not steps — they start after the
pipeline, from Laravel's registry.
