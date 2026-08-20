# Laravel Boot-Up

A development-only Laravel package that boots a project on any machine — even a
blank one — with two commands, and cleanly shuts it down again. It builds on
Laravel's own `php artisan dev` rather than replacing it.

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
php artisan dev
```

`dev` installs the tools you're missing, creates your `.env`, sets up the
database, installs dependencies, runs migrations, builds assets, and serves the
app via **Herd**, **Sail**, or **`php artisan serve`** — then hands the queue
worker, the asset watcher and everything else to Laravel's own dev terminal,
where each process gets its own searchable tab. `app:down` stops everything it
started — and nothing it didn't.

This is Laravel's `php artisan dev` with the boot in front of it, so your own
processes join it exactly as they would in any Laravel application:

```php
use Illuminate\Foundation\DevCommands;

DevCommands::register('stripe listen --forward-to '.config('app.url'))->orange();
```

boot-up only decides which of the built-in processes your project actually
needs — no queue worker on a `sync` connection, no log tail without Pail, no
`vite` without a `dev` script, and every command rewritten to run inside Sail's
containers when that is where the project lives. See
[Dev processes](docs/EXTENDING.md#dev-processes).

## Commands

| Command                  | What it does                                                          |
| ------------------------ | --------------------------------------------------------------------- |
| `dev`                    | Boot everything the application needs, then run the dev processes.    |
| `app:down`               | Stop tracked processes and the server `dev` started.                  |
| `app:status`             | Show the active server and tracked processes.                         |
| `app:deploy`             | Install, run project commands and migrate — without booting a server. |
| `generate:deploy-script` | Export a paste-ready deployment script (Forge, fortrabbit).           |
| `generate:pipeline`      | Generate a CI/CD pipeline (GitHub, Bitbucket) plus shared scripts.    |
| `generate:git-hooks`     | Install a tracked pre-commit hook running the pipeline's Pint check.  |

Every flag is documented in [docs/COMMANDS.md](docs/COMMANDS.md) — and
`php artisan list` / `php artisan dev --help` are always authoritative.

## Documentation

- Commands and options: [docs/COMMANDS.md](docs/COMMANDS.md)
- Configuration (publish with `php artisan vendor:publish --tag=boot-up-config`):
  [docs/CONFIGURATION.md](docs/CONFIGURATION.md)
- Deployment scripts: [docs/DEPLOYMENTS.md](docs/DEPLOYMENTS.md)
- CI/CD pipelines: [docs/PIPELINES.md](docs/PIPELINES.md)
- Extending the package (dev processes, servers, steps, tools, platforms,
  providers): [docs/EXTENDING.md](docs/EXTENDING.md)
- Project commands around deploys: [docs/CUSTOM_COMMANDS.md](docs/CUSTOM_COMMANDS.md)

## Testing

```bash
composer test          # Pest suite (unit + feature + architecture tests)
composer analyse       # PHPStan, level 6, no baseline
vendor/bin/pint        # code style
```

The pre-commit hook runs Pint on staged files and PHPStan across the package.
Analysis covers `src/` and `config/`; tests are left out because Pest binds
`$this` inside test closures at runtime, which static analysis reads as Pest's
own `TestCall` and reports by the thousand.

## License

- This package: [MIT License](LICENSE)
