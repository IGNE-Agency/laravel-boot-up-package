# Laravel Boot-Up

A development-only Laravel package that boots a project on any machine — even a
blank one — with two commands, and cleanly shuts it down again.

> Requires PHP 8.3+, Laravel 13, macOS or Linux (native Windows fails fast with
> a clear message — use WSL2). Development only.

## Installation

```bash
composer require igne-agency/laravel-boot-up --dev
```

The package auto-registers via Laravel's package discovery. Always use `--dev`;
this package has no business in production.

## Quickstart

```bash
composer install
php artisan app:serve
```

`app:serve` installs the tools you're missing, creates your `.env`, sets up the
database, installs dependencies, runs migrations, builds or watches assets,
starts the workers your project needs, and serves the app via **Herd**,
**Sail**, or **`php artisan serve`**. `app:down` stops everything it started —
and nothing it didn't.

## Usage

### `app:serve`

Boot everything the application needs and serve it locally.

```bash
php artisan app:serve
```

| Argument / option  | Description                                                                                    |
| ------------------ | ---------------------------------------------------------------------------------------------- |
| `server`           | The development server to use: `herd`, `sail` or `laravel`. Prompts on first run when omitted. |
| `--seed` / `-s`    | Seed the database after migrating — always, even with nothing to migrate.                      |
| `--fresh`          | Drop all tables and re-run every migration (asks first).                                       |
| `--no-migrate`     | Skip running pending migrations.                                                               |
| `--update` / `-u`  | Update dependencies instead of installing.                                                     |
| `--without-queue`  | Do not start a queue worker.                                                                   |
| `--without-assets` | Skip frontend dependencies and assets.                                                         |

### `app:down`

Stop tracked processes and the server `app:serve` started. After a failed
Sail boot it also offers `sail down` to clear leftover Docker resources
(stopped containers, networks, half-pulled images).

```bash
php artisan app:down
```

### `app:status`

Show the active server and tracked processes, with their log paths.

```bash
php artisan app:status
```

### `app:deploy`

Install dependencies, run project commands and migrate — without booting a
server. Like `app:serve`, it prints the plan up front and tracks a progress bar
as it runs.

```bash
php artisan app:deploy
```

| Option            | Description                                              |
| ----------------- | -------------------------------------------------------- |
| `--seed` / `-s`   | Seed the database after migrating.                       |
| `--fresh`         | Drop all tables and re-run every migration (asks first). |
| `--no-migrate`    | Skip running pending migrations.                         |
| `--update` / `-u` | Update dependencies instead of installing.               |

### `generate:deploy-script`

Export a deployment script for a hosting platform, based on this package's
config. See [Deployment scripts](#deployment-scripts).

```bash
php artisan generate:deploy-script
```

| Argument / option | Description                                                                             |
| ----------------- | --------------------------------------------------------------------------------------- |
| `platform`        | The hosting platform: `forge` or `fortrabbit`. Prompts when omitted.                    |
| `environment`     | The target environment: `development`, `staging` or `production`. Prompts when omitted. |
| `--classic`       | Forge only — generate for classic (non-zero-downtime) sites.                            |
| `--output=`       | Write the script to a file instead of printing it.                                      |

### `generate:pipeline`

Generate a CI/CD pipeline, its shared `scripts/ci` files and `.env.pipeline` for
a git provider. See [CI/CD pipelines](#cicd-pipelines).

```bash
php artisan generate:pipeline
```

| Argument / option | Description |
| --- | --- |
| `provider` | The git provider: `github` or `bitbucket`. Prompts when omitted. |
| `host` | The deploy-hook host: `fortrabbit`, `forge` or `webhook` — or `none` to skip the deploy step. Prompts when omitted. |
| `--force` | Overwrite existing pipeline, `scripts/ci` and `.env.pipeline` files without asking. |
| `--regenerate-app-key` | Generate a fresh `APP_KEY` in `.env.pipeline` instead of keeping the existing one. |

### `generate:git-hooks`

Install a tracked pre-commit hook that runs the pipeline's Pint check locally
before each commit (requires `laravel/pint`). See [CI/CD pipelines](#cicd-pipelines).

```bash
php artisan generate:git-hooks
```

The hook lives in `.githooks/` and is shared by pointing `git config core.hooksPath`
at it — commit `.githooks/` so your whole team gets it.

## What `app:serve` does

Before booting, `app:serve` prints exactly what it will do, in order, and then
works through that plan. The steps and their order are published config
(`boot-up.serve_steps`) — reorder, remove or extend them as you like.

## Long-running processes

Long-running processes (queue worker, asset watcher, scheduler, Horizon, Reverb)
**open in their own terminal window by default**, so you see their output live.
Prefer them out of sight? Set the matching config or env value to `background`
(e.g. `BOOT_UP_QUEUE_RUN_IN=background`) to run them detached with their output
in `storage/logs/boot-up/`. Either way they are tracked, so `app:down` stops
exactly them.

## Shutdown

`app:down` (and Ctrl-C during `app:serve`) stops every tracked process, then
asks whether to stop the server — only the server that `app:serve` itself
started. The prompt and its default answer are configurable via the `shutdown`
config keys.

## Configuration

```bash
php artisan vendor:publish --tag=boot-up-config
```

Every option — servers, tools and version constraints, database, frontend, queue
and services, shutdown behavior, and the full step pipelines — is documented in
[docs/CONFIGURATION.md](docs/CONFIGURATION.md).

## Server extras

### Sail

When serving with Sail, the package offers (once, with your consent) to add the
`sail` alias to your shell profile, and runs every app-level command inside the
containers (`./vendor/bin/sail artisan ...`) automatically.

A `sail up` that fails because the application image was never built (an
earlier boot died mid-scaffold) is retried with `--build` automatically, and an
unreachable Docker registry fails with network guidance instead of raw compose
errors. When you switch between Sail and another server, the boot detects the
`DB_HOST` the other server left in `.env` (Sail's `mysql` vs the host's
`127.0.0.1`) and offers to fix it.

### Herd

On first link you choose the site name — the folder name is the default, so
`https://{name}.test` can differ from the directory. Pin it with
`BOOT_UP_HERD_SITE` to skip the prompt. Herd's site registry is verified against
the actual project path, so a link pointing at a moved project is repaired
automatically.

`app:serve` does not trust "Herd started": it boots Herd if its processes are
down and then waits for Nginx to actually answer the site, restarting an
unhealthy Herd between checks and failing with actionable guidance if it never
comes up. Tune the check with `server.herd.health.*` (see
[docs/CONFIGURATION.md](docs/CONFIGURATION.md)).

## Deployment scripts

`generate:deploy-script` turns your boot-up config into a paste-ready deployment
script for your hosting platform, tuned per environment: `development` keeps dev
dependencies, `staging` and `production` optimize for release.

- **Forge** — Laravel's server-management platform. Generates a
  **zero-downtime** script by default: each deploy builds a fresh release and
  switches over atomically, so visitors never hit a half-deployed app. Use
  `--classic` for sites created without zero-downtime deployments (git pull in
  place) — the two styles cannot be mixed.
- **fortrabbit** — Laravel-focused PaaS hosting. Generates the two command lists
  its dashboard expects per environment: build commands and post-deploy
  commands.

Details per platform: [docs/DEPLOYMENTS.md](docs/DEPLOYMENTS.md).

## CI/CD pipelines

`generate:pipeline` generates a pipeline for your git provider, plus shared shell
scripts that hold all the logic — so every stage also runs locally and both
providers behave identically. Checks run on every pull request; deploys are
branch-scoped: a green push on a deploy branch triggers your host's deploy
webhook for that branch's environment only.

- **GitHub** — GitHub Actions workflow, deploy environments and secrets under
  the repository settings.
- **Bitbucket** — Bitbucket Pipelines, deploy environments under the
  repository's deployment settings.

Extend a generated pipeline without replacing the generator: inject extra steps
into a job or emit extra files verbatim via `boot-up.pipeline.steps` /
`boot-up.pipeline.files`. Regeneration is idempotent and validated.

Details, secrets, extensions and branch mapping:
[docs/PIPELINES.md](docs/PIPELINES.md).

## Extending the package

None of these require touching package code:

| Extension point                    | Implement                        | Register                                           |
| ---------------------------------- | -------------------------------- | -------------------------------------------------- |
| Project commands around migrations | `Contracts\ProvidesDeployTasks` | Bind as singleton in your `AppServiceProvider`     |
| Custom serve/deploy steps          | `Contracts\Step`                     | Insert into `boot-up.serve_steps` / `deploy_steps` |
| Custom servers                     | `Contracts\Server`                 | `boot-up.server.drivers`                           |
| Custom tools                       | `Contracts\InstallsTool`             | `boot-up.tools.installers` + `tools.required`      |
| Custom deployment platforms        | `Contracts\ScriptGenerator` | `boot-up.deploy.script_generators`                 |
| Custom git providers               | `Contracts\PipelineGenerator`    | `boot-up.pipeline.generators`                      |

How-to per extension point: [docs/EXTENDING.md](docs/EXTENDING.md).

## Testing

```bash
composer test          # Pest 4 suite (unit + feature + architecture tests)
vendor/bin/pint        # code style
```

## Documentation

- Configuration: [docs/CONFIGURATION.md](docs/CONFIGURATION.md)
- Deployment scripts: [docs/DEPLOYMENTS.md](docs/DEPLOYMENTS.md)
- CI/CD pipelines: [docs/PIPELINES.md](docs/PIPELINES.md)
- Extending the package: [docs/EXTENDING.md](docs/EXTENDING.md)
- Project commands: [docs/CUSTOM_COMMANDS.md](docs/CUSTOM_COMMANDS.md)

## License

- Laravel: [MIT License](https://opensource.org/licenses/MIT)
- This package: [MIT License](LICENSE)
