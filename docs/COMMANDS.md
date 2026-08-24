# Commands

The authoritative reference is always the CLI itself — `php artisan list`
shows every command, and `php artisan app:setup --help` (or any other
command with `--help`) shows its full signature. This page mirrors those
signatures with more context.

Two commands cover the daily loop: `app:setup` gets the project ready, `dev`
runs it. Setting up is what you do after a clone; running is what you do every
day, which is why they are separate — `app:setup` still ends in `dev`, so a
fresh clone is one command from a running application.

## `app:setup`

Set up the application, start its development server, and run it. Prints the
plan first, asks to continue, then works through it with a progress bar and a
section divider per stage. Ctrl+C tears down everything it started so far.

When the boot is done it hands the terminal to `php artisan dev` — the same
terminal UI, the same tabs — and stays behind it. Quit that terminal and it
runs `app:down` for you, so the server and everything else the boot started is
stopped again. The whole session is one command; nothing is left running that
you did not ask for.

Because the run does not end until you quit the dev terminal, this is a
command for a person at a keyboard. Scripted, unattended work belongs to
[`app:deploy`](#appdeploy), which never boots a server.

```bash
php artisan app:setup
```

| Argument / option  | Description                                                                                                                           |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------- |
| `server`           | The development server to use: `herd`, `sail`, `artisan`, or any driver registered in `boot-up.server.drivers`. Prompts when omitted. |
| `--seed` / `-s`    | Seed the database after migrating — always, even with nothing to migrate.                                                              |
| `--fresh`          | Drop all tables and re-run every migration (asks first).                                                                              |
| `--no-migrate`     | Skip running pending migrations. Wins over `--fresh` as the least destructive option.                                                 |
| `--update` / `-u`  | Update dependencies instead of installing.                                                                                            |
| `--without-assets` | Skip frontend dependencies and assets.                                                                                                |
| `--yes` / `-y`     | Run without the confirmation prompt (config: `setup.auto_accept`).                                                                    |

## `dev`

Run the dev processes this project needs, in Laravel's dev terminal — one
searchable tab per process. This *is* Laravel's own `dev` command, so every
option it defines works here too: `--tabs`, `--stream`, `--inline`,
`--timestamps`, `--no-restart`, `--json` and the buffer sizes, and so do its
keys: `↑`/`↓` scroll, `tab` cycles tabs, `c` clears, `/` searches, `s` streams,
`r` restarts the current process, `q` quits. `php artisan dev:list` shows the
registered processes without starting them.

boot-up adds only the process list: which of the built-in processes this project
can use, and the server rewrite that runs them inside Sail's containers when
that is where the project lives.

Before handing off it checks that the project is set up — a `.env` with an
`APP_KEY`, installed dependencies, a known server — and names `app:setup` if
anything is missing. Those are filesystem reads, so the terminal appears
immediately.

```bash
php artisan dev
```

| Argument / option  | Description                                                                                                                    |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------ |
| `server`           | Override the server `app:setup` recorded: `herd`, `sail`, `artisan`, or any driver registered in `boot-up.server.drivers`.     |
| `--without-queue`  | Do not run a queue worker.                                                                                                      |
| `--without-assets` | Do not run an asset watcher.                                                                                                    |
| `--detach` / `-d`  | Run the dev processes in the background instead of this terminal, with logs in `storage/logs/boot-up/`.                        |

Run on its own, quitting the terminal leaves the project set up and its server
running — that is what `app:down` is for. A `dev` that `app:setup` started
instead hands control back to it, and the teardown follows automatically.

The dev terminal needs Node 22.13 or newer. Below that, `--detach` runs the
same processes without it.

## `app:down`

Stop tracked processes and the server `php artisan app:setup` started — and
nothing it didn't. After a failed Sail boot it also offers `sail down` to clear
leftover Docker resources (stopped containers, networks, half-pulled
images).

```bash
php artisan app:down
```

No arguments or options; the stop-server prompt is configured by the
`shutdown.*` keys.

## `app:status`

Show the active server and tracked processes, with the log file each one's
output goes to. Only a detached run has tracked processes: in the foreground
they belong to the dev terminal.

```bash
php artisan app:status
```

## `app:deploy`

Install dependencies, run project commands and migrate — without booting a
server, running dev processes, or opening a browser. Like `app:setup`, it prints
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
