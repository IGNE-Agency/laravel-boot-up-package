# Commands

The authoritative reference is always the CLI itself — `php artisan list`
shows every command, and `php artisan app:serve --help` (or any other
command with `--help`) shows its full signature. This page mirrors those
signatures with more context.

## `app:serve`

Boot everything the application needs and serve it locally. Prints the plan
first, asks to continue, then works through it with a progress bar and a
section divider per stage. Long-running workers stream into the same
terminal by default; Ctrl+C tears everything down.

```bash
php artisan app:serve
```

| Argument / option  | Description                                                                                                                           |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------- |
| `server`           | The development server to use: `herd`, `sail`, `artisan`, or any driver registered in `boot-up.server.drivers`. Prompts when omitted. |
| `--seed` / `-s`    | Seed the database after migrating — always, even with nothing to migrate.                                                             |
| `--fresh`          | Drop all tables and re-run every migration (asks first).                                                                              |
| `--no-migrate`     | Skip running pending migrations. Wins over `--fresh` as the least destructive option.                                                 |
| `--update` / `-u`  | Update dependencies instead of installing.                                                                                            |
| `--without-queue`  | Do not start a queue worker.                                                                                                          |
| `--without-assets` | Skip frontend dependencies and assets.                                                                                                |
| `--detach` / `-d`  | Do not stream combined worker output; run everything detached with logs in `storage/logs/boot-up/`.                                   |
| `--yes` / `-y`     | Run without the confirmation prompt (config: `serve.auto_accept`).                                                                    |

## `app:down`

Stop tracked processes and the server `app:serve` started — and nothing it
didn't. After a failed Sail boot it also offers `sail down` to clear
leftover Docker resources (stopped containers, networks, half-pulled
images).

```bash
php artisan app:down
```

No arguments or options; the stop-server prompt is configured by the
`shutdown.*` keys.

## `app:status`

Show the active server and tracked processes, with where each one's output
lives (the combined stream, a terminal window, or its log file).

```bash
php artisan app:status
```

## `app:deploy`

Install dependencies, run project commands and migrate — without booting a
server, starting workers, or opening a browser. Like `app:serve`, it prints
the plan up front and tracks a progress bar as it runs.

```bash
php artisan app:deploy
```

| Option            | Description                                                         |
| ----------------- | ------------------------------------------------------------------- |
| `--seed` / `-s`   | Seed the database after migrating.                                  |
| `--fresh`         | Drop all tables and re-run every migration (asks first).            |
| `--no-migrate`    | Skip running pending migrations.                                    |
| `--update` / `-u` | Update dependencies instead of installing.                          |
| `--yes` / `-y`    | Run without the confirmation prompt (config: `deploy.auto_accept`). |

## `generate:deploy-script`

Export a deployment script for a hosting platform, based on this package's
config and tuned per environment. Details per platform:
[DEPLOYMENTS.md](DEPLOYMENTS.md).

```bash
php artisan generate:deploy-script
```

| Argument / option | Description                                                                                                                           |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `platform`        | The hosting platform: `forge`, `fortrabbit`, or any generator registered in `boot-up.deploy.script_generators`. Prompts when omitted. |
| `environment`     | The target environment: `development`, `staging` or `production`. Prompts when omitted.                                               |
| `--classic`       | Forge only — generate for classic (non-zero-downtime) sites.                                                                          |
| `--output=`       | Write the script to a file instead of printing it.                                                                                    |

## `generate:pipeline`

Generate a CI/CD pipeline, its shared `scripts/ci` files and
`.env.pipeline` for a git provider. Details, secrets and branch mapping:
[PIPELINES.md](PIPELINES.md).

```bash
php artisan generate:pipeline
```

| Argument / option      | Description                                                                                                                  |
| ---------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `provider`             | The git provider: `github`, `bitbucket`, or any generator registered in `boot-up.pipeline.generators`. Prompts when omitted. |
| `host`                 | The deploy-hook host: `fortrabbit`, `forge` or `webhook` — or `none` to skip the deploy step. Prompts when omitted.          |
| `--force`              | Overwrite existing pipeline, `scripts/ci` and `.env.pipeline` files without asking.                                          |
| `--regenerate-app-key` | Generate a fresh `APP_KEY` in `.env.pipeline` instead of keeping the existing one.                                           |

## `generate:git-hooks`

Install a tracked pre-commit hook that runs the pipeline's Pint check
locally before each commit (requires `laravel/pint`). The hook lives in
`.githooks/` and is shared by pointing `git config core.hooksPath` at it —
commit `.githooks/` so your whole team gets it.

```bash
php artisan generate:git-hooks
```
