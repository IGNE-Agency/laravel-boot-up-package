# Laravel Boot-Up

Boot a Laravel project on any machine — even a blank one — with two commands:

```bash
composer install
php artisan app:serve
```

`app:serve` installs or updates the tools you're missing (Homebrew, PHP, Node,
Composer, Docker, Herd, bun/yarn/npm/pnpm), creates your `.env`, sets up the
database (prompting for credentials when they're missing), runs pending
migrations, installs dependencies, builds or watches assets, starts a queue
worker when your project needs one, and serves the app via **Herd**, **Sail**,
or **`php artisan serve`**. `app:down` cleanly stops everything it started — and
nothing it didn't.

> Development only. Requires PHP 8.3+, Laravel 13, macOS or Linux.

## Installation

```bash
composer require igne-agency/laravel-boot-up --dev
```

The package auto-registers via Laravel's package discovery. Always use `--dev`;
this package has no business in production.

## Usage

```bash
php artisan app:serve            # prompts for a server on first run
php artisan app:serve herd       # or: sail | laravel
php artisan app:serve --seed     # seed after migrating
php artisan app:serve --update   # update dependencies instead of installing
php artisan app:serve --no-migrate --without-queue --without-assets

php artisan app:deploy           # dependencies + project commands + migrations, no server
php artisan app:down             # stop tracked processes + the server app:serve started

php artisan app:deploy-script forge production        # export a hosting deployment script
php artisan app:deploy-script fortrabbit staging      # (see "Exporting a deployment script")

php artisan app:pipeline github                       # generate a CI/CD pipeline + .env.pipeline
php artisan app:pipeline bitbucket                    # (see "Generating a CI/CD pipeline")
```

### What `app:serve` does, in order

Every step is a small class; the full ordered list is published config you can
reorder, trim, or extend (`boot-up.serve_steps`):

1. Create `.env` from `.env.example` when missing
1. Guard against non-local environments (a fresh `.env` counts as local)
1. Generate `APP_KEY` when empty
1. Install missing tools / update ones that violate your version constraints
1. Start the chosen server (Herd link+secure · Docker+Sail up ·
   `php artisan serve`)
1. `composer install` (or `--update`)
1. Frontend install with bun/yarn/npm/pnpm
1. Prompt for missing `DB_*` env values and write them to `.env`
1. Create the database when it doesn't exist, verify the connection
1. Your project's `beforeMigrations()` commands
1. Run migrations — only when there are pending ones
1. Your project's `afterMigrations()` commands
1. Optional framework caching (off by default) + finalize (`storage:link`)
1. Start `queue:work` — only when `QUEUE_CONNECTION` is not `sync`
1. Build or watch assets
1. Announce the URL and open your browser

Long-running processes (queue worker, asset watcher, `php artisan serve`) run
detached in the background with their output in `storage/logs/boot-up/` and
their PIDs tracked in `storage/framework/boot-up/`, so `app:down` can reap
exactly them. Set `BOOT_UP_QUEUE_RUN_IN=terminal` (or
`BOOT_UP_ASSETS_WATCH_IN=terminal`) to open real terminal windows instead.

### Shutdown behavior

- `app:down` (and Ctrl-C during `app:serve`) stops every tracked background
  process, then asks whether to stop the server — **only the server that
  `app:serve` itself started**. A Herd or Sail that was already running before
  you served is left alone.
- Re-running `app:serve` never stacks duplicate workers or watchers, and a
  second `app:down` is a friendly no-op.

## Configuration

```bash
php artisan vendor:publish --tag=boot-up-config
```

Highlights of `config/boot-up.php`:

```php
'server' => [
    'default' => env('BOOT_UP_SERVER'),           // 'herd' | 'sail' | 'laravel' | null = prompt
    'drivers' => [ /* add your own Server implementations here */ ],
    'herd' => [
        'site' => env('BOOT_UP_HERD_SITE'),       // https://{site}.test; null = prompt (folder name as default)
    ],
],

'tools' => [
    'auto_install' => true,
    'auto_update' => true,                          // update when the constraint is violated
    'required' => [
        'php' => env('BOOT_UP_PHP_VERSION', '*'), // composer-style constraints: '^8.3', '*'
        'node' => env('BOOT_UP_NODE_VERSION', '*'),
        'composer' => env('BOOT_UP_COMPOSER_VERSION', '*'),
    ],
],

'frontend' => [
    'package_manager' => env('BOOT_UP_PACKAGE_MANAGER', 'bun'), // bun | yarn | npm | pnpm
    'assets' => env('BOOT_UP_ASSETS', 'watch'),                 // watch | build | skip
],

'serve_steps' => [ /* the entire pipeline, reorderable */ ],
'deploy_steps' => [ /* what app:deploy runs */ ],
```

Sail extras: when serving with Sail, the package offers (once, with your
consent) to add the `sail` alias to your shell profile, and rewrites every
app-level command to run inside the containers
(`./vendor/bin/sail artisan ...`).

Herd extras: `app:serve herd` verifies Herd's site registry against the actual
project path. A link pointing at a moved project (the classic
"folder-was-relocated 404") is replaced automatically; a name owned by another
live project is only taken over after you confirm. On first link you choose the
site name — the folder name is the default, so `https://{name}.test` can differ
from the directory. Pin it with `BOOT_UP_HERD_SITE` to skip the prompt.

## Exporting a deployment script

`app:deploy-script` turns this package's config (package manager, migrations,
finalize commands, queue usage, and your bound project commands) into a
paste-ready deployment script for your hosting platform:

```bash
php artisan app:deploy-script forge production        # zero-downtime release script (Forge's default)
php artisan app:deploy-script forge production --classic   # for older sites without zero-downtime deployments
php artisan app:deploy-script fortrabbit staging      # "Build commands" + "Post deploy commands" sections
php artisan app:deploy-script                         # prompts for platform + environment
php artisan app:deploy-script forge production --output=deploy.sh
```

- **Forge** — the zero-downtime script uses Forge's release macros
  (`$CREATE_RELEASE()`, `$ACTIVATE_RELEASE()`, `$RESTART_QUEUES()`); paste it
  into your site's deployment script. The `--classic` variant (git pull +
  PHP-FPM reload) is for sites created without zero-downtime deployments — the
  two styles cannot be mixed.
- **Fortrabbit** — outputs the two command lists the fortrabbit dashboard
  expects per environment: _Build commands_ (composer + frontend build; the
  package manager is `npm i -g`-installed when it isn't npm) and _Post deploy
  commands_ (migrations, optimize, finalize, queue restart).
- The **environment** argument tunes the script: `development` keeps dev
  dependencies and skips `artisan optimize`; `staging`/`production` add
  `--no-dev` and framework caching.
- Your `ProvidesProjectCommands` binding is embedded at the right positions
  (before/after migrations), with descriptions as comments.

## Generating a CI/CD pipeline

`app:pipeline` writes a ready-to-commit pipeline for your git provider plus a
`.env.pipeline` test environment, both at their canonical paths:

```bash
php artisan app:pipeline github       # .github/workflows/ci.yml + .env.pipeline
php artisan app:pipeline bitbucket    # bitbucket-pipelines.yml + .env.pipeline
php artisan app:pipeline              # prompts for the provider
php artisan app:pipeline github --force   # overwrite existing files without asking
```

Both providers run the **identical command sequence** (generated from one shared
source): composer install with caching, `cp .env.pipeline .env`, Nova publish
(only when `laravel/nova` is in your `composer.json` — composer auth comes from
a `COMPOSER_AUTH` secret), lockfile-strict frontend install + build (driven by
your `frontend.package_manager` config), finalize commands, project commands,
`migrate --force`, and `php artisan test`. When `laravel/pint` is installed, a
**lint job** (`pint --test`) runs in parallel with the tests on both providers,
and deploys wait for both to pass.

- **Tests** run on every pull request and on pushes to the deploy branches,
  against in-memory SQLite (`.env.pipeline` carries the config — commit it; the
  generated `APP_KEY` is only ever used in CI).
- **Deploys** are webhook-based: after a green push, the pipeline curls the hook
  named in `boot-up.pipeline.branches` (defaults: `develop` → `DEV_DEPLOY`,
  `staging` → `STAGING_DEPLOY`, `master` → `PROD_DEPLOY`). Point each secret at
  your host's deploy trigger URL (e.g. Forge's "Deployment trigger URL"). An
  unset hook skips that deploy with a notice instead of failing the run. On a
  `main` repo, remap `boot-up.pipeline.branches` and regenerate.
- **PHP version** comes from your `composer.json` `require.php` (setup-php on
  GitHub, the `laravelsail/php{XY}-composer` image on Bitbucket).
- After generating, the command prints exactly which secrets/variables to create
  and where (GitHub → Actions secrets; Bitbucket → secured repository
  variables + enabling Pipelines once). Nothing else needs to change in the git
  provider.

## Extending the package

Four extension points, none of which require touching package code:

1. **Project commands** — generators and warmers that run before/after
   migrations. See [CUSTOM_COMMANDS.md](CUSTOM_COMMANDS.md) and
   [examples/ProjectCommands.php](examples/ProjectCommands.php).
1. **Custom pipeline steps** — implement `Serve\Step` and insert the class
   anywhere in the published `serve_steps` / `deploy_steps` arrays.
1. **Custom servers** — implement `Servers\Server` and register it under
   `server.drivers` (e.g. `'valet' => ValetServer::class`). It becomes
   selectable by argument, config, and prompt, and participates in state
   tracking, shutdown, and command rewriting automatically.
1. **Custom tools** — implement `Tools\InstallsTool` and map it under
   `tools.installers` with a constraint under `tools.required`. Config wins on
   key collision, so you can also replace a built-in installer (e.g. install
   Node via nvm).
1. **Custom deployment platforms** — implement `Deploy\Scripts\ScriptGenerator`
   and register it under `deploy.script_generators` (e.g.
   `'envoyer' => EnvoyerScriptGenerator::class`); it becomes selectable in
   `app:deploy-script` alongside Forge and Fortrabbit.
1. **Custom git providers** — implement `Pipelines\PipelineGenerator` and
   register it under `pipeline.generators` (e.g.
   `'gitlab' => GitlabPipelineGenerator::class`); it becomes selectable in
   `app:pipeline` alongside GitHub and Bitbucket.

## Testing

```bash
composer test          # Pest 4 suite (unit + feature + architecture tests)
vendor/bin/pint        # code style
```

## License

MIT — see [LICENSE](LICENSE).
