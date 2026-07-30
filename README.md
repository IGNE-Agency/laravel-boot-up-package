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
**Sail**, or **`php artisan serve`**. Workers stream into the same terminal;
Ctrl+C stops everything. `app:down` stops everything it started — and nothing
it didn't.

## Commands

| Command                  | What it does                                                          |
| ------------------------ | --------------------------------------------------------------------- |
| `app:serve`              | Boot everything the application needs and serve it locally.           |
| `app:down`               | Stop tracked processes and the server `app:serve` started.            |
| `app:status`             | Show the active server and tracked processes.                         |
| `app:deploy`             | Install, run project commands and migrate — without booting a server. |
| `generate:deploy-script` | Export a paste-ready deployment script (Forge, fortrabbit).           |
| `generate:pipeline`      | Generate a CI/CD pipeline (GitHub, Bitbucket) plus shared scripts.    |
| `generate:git-hooks`     | Install a tracked pre-commit hook running the pipeline's Pint check.  |

Every flag is documented in [docs/COMMANDS.md](docs/COMMANDS.md) — and
`php artisan list` / `php artisan app:serve --help` are always authoritative.

## Documentation

- Commands and options: [docs/COMMANDS.md](docs/COMMANDS.md)
- Configuration (publish with `php artisan vendor:publish --tag=boot-up-config`):
  [docs/CONFIGURATION.md](docs/CONFIGURATION.md)
- Deployment scripts: [docs/DEPLOYMENTS.md](docs/DEPLOYMENTS.md)
- CI/CD pipelines: [docs/PIPELINES.md](docs/PIPELINES.md)
- Extending the package (servers, steps, tools, platforms, providers):
  [docs/EXTENDING.md](docs/EXTENDING.md)
- Project commands around deploys: [docs/CUSTOM_COMMANDS.md](docs/CUSTOM_COMMANDS.md)

## Testing

```bash
composer test          # Pest suite (unit + feature + architecture tests)
vendor/bin/pint        # code style
```

## License

- This package: [MIT License](LICENSE)
