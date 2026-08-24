# Extending the package

Seven extension points, none of which require touching package code. The first
is Laravel's own API rather than boot-up's — extra dev processes are registered
the same way in any Laravel application, and boot-up only decides which of the
built-in ones this project needs.

## Dev processes

`php artisan dev` *is* Laravel's own command, so extra long-running processes
are registered the ordinary Laravel way — from any service provider's `boot()`,
with no boot-up API involved:

```php
use Illuminate\Foundation\DevCommands;

DevCommands::register('stripe listen --forward-to '.config('app.url'))->orange();
DevCommands::artisan('reverb:start', 'reverb');
DevCommands::node('dev', 'vite');
DevCommands::nodeExec('vite --port 3000');
```

Laravel's own documentation covers that API in full: naming, the six colours,
`only()` / `except()`, and the priority rule that an application's registration
always outranks a package's. Anything registered this way also shows up in
`php artisan dev:list`.

`DevCommands` has no per-process environment or working directory, because the
command is a shell string — write what you need into it:

```php
DevCommands::register('STRIPE_KEY=sk_test stripe listen');
DevCommands::register('sh -c "cd services/api && npm run watch"');
```

### What boot-up adds

The framework registers four processes unconditionally: `server`, `queue`,
`logs` and `vite`. boot-up decides which of them this project can actually
use, and rewrites each command for the server that booted:

| Process     | boot-up's decision                                                                |
| ----------- | --------------------------------------------------------------------------------- |
| `server`    | The driver's own command — or none at all under Herd, which serves the app itself. |
| `queue`     | `queue:work` on the connection from `.env`, dropped on `sync` or when Horizon runs the queue. |
| `horizon`   | Started when `laravel/horizon` is installed and enabled.                           |
| `reverb`    | Started when `laravel/reverb` is installed and enabled.                            |
| `scheduler` | `schedule:work`, opt-in through `boot-up.scheduler.enabled`.                       |
| `vite`      | The package manager boot-up installed with, dropped without a `dev` script.        |
| `logs`      | Left to the framework, dropped when `laravel/pail` is not installed.               |

Your own registration always wins: register under one of those names and
boot-up leaves it alone. It also leaves a `server` another package registered —
Octane's, for instance — untouched. For the rest it takes a package's
registration over, because it is the only party that knows the command has to
run inside Sail's containers.

`app:setup` lists the processes `dev` will run, in the order their tabs will
appear — third-party registrations included — and then hands the terminal to
`dev` to run them.

Under `php artisan dev --detach` every process starts detached instead, with a
log file in `storage/logs/boot-up/`, visible to `app:status` and stoppable with
`app:down`.

## Project commands

Generators and warmers that run across four deploy phases (`beforeDeploy`,
`beforeMigrations`, `afterMigrations`, `afterDeploy`) during `app:setup` /
`app:deploy` and get embedded in exported deployment scripts.

1. Implement `Igne\LaravelBootUp\Contracts\ProvidesDeployTasks` (all four
   methods; return `[]` for phases you don't use).
2. Bind it as a singleton in your `AppServiceProvider::register()`.

Full guide: [CUSTOM_COMMANDS.md](CUSTOM_COMMANDS.md) — example:
[examples/DeployTasks.php](../examples/DeployTasks.php).

## Custom pipeline steps

1. Implement `Igne\LaravelBootUp\Contracts\Step`.
2. Insert the class anywhere in the published `boot-up.setup.steps` / `boot-up.deploy.steps` arrays.

Print through the package's terminal for consistent styling: the global
`terminal()` helper (no import needed — `terminal()->success('Done.')`) or the
`Igne\LaravelBootUp\Facades\Terminal` facade; both resolve the same singleton,
so your output plays nicely with the boot progress bar. A
`Igne\LaravelBootUp\Facades\Platform` facade (`Platform::isWindows()`) exists
too.

## Custom servers

1. Implement `Igne\LaravelBootUp\Contracts\Server`.
2. Register it under `boot-up.server.drivers`, e.g.
   `'valet' => ValetServer::class`.

It becomes selectable by argument, config, and prompt, and participates in state
tracking, shutdown, and command rewriting automatically.

Beyond the core `Server` interface, optional capability interfaces opt your
driver into extra behaviour:

- `ProvidesDatabase` — the server provisions the database itself, so creation
  is skipped; `databaseReachableFromHost()` returns `false` to route database
  checks and migrations through your command rewrites (like Sail does).
- `WarnsBeforeStop` — `stopImpact()` describes what stopping reaches beyond
  this project; it is shown and never acted on without an explicit yes (like
  Herd's machine-wide stop).
- `HasResidualState` — a failed boot can leave state behind even when the
  server reports not-running (like Sail's stopped containers). Shutdown shows
  `residualStateImpact()` and offers `cleanUpResidualState()` instead of
  silently skipping the server.
- `RequiresTools` — `requiredTools()` lists tools the server needs installed
  (like Sail's Docker).
- `RewritesCommands` — `commandRewrites()` reroutes project commands through
  the server (like Sail's `./vendor/bin/sail` prefix).
- `ProvidesDevProcess` — `devProcess()` gives the command that runs as the
  `[server]` dev process, or `null` when the server is external to the run
  (Herd serves through its own nginx, so it has no process here) or has
  already been started some other way.

## Custom tools

1. Implement `Igne\LaravelBootUp\Contracts\InstallsTool`.
2. Map it under `boot-up.tools.installers` with a version constraint under
   `boot-up.tools.required`.

Config wins on key collision, so you can also replace a built-in installer (e.g.
install Node via nvm).

## Custom deployment platforms

1. Implement `Igne\LaravelBootUp\Contracts\ScriptGenerator`.
2. Register it under `boot-up.deploy.script_generators`, e.g.
   `'envoyer' => EnvoyerScriptGenerator::class`.

It becomes selectable in `generate:deploy-script` alongside Forge and fortrabbit.

## Custom git providers

1. Implement `Igne\LaravelBootUp\Contracts\PipelineGenerator` — `files()`
   returns the `GeneratedFile`s to write, `secrets()` the instructions-table
   rows and their detail sections, and `anchors()` the job names project config
   may inject extra steps into (see
   [Extending the pipeline](PIPELINES.md#extending-the-pipeline)).
2. Register it under `boot-up.pipeline.generators`, e.g.
   `'gitlab' => GitlabPipelineGenerator::class`.

It becomes selectable in `generate:pipeline` alongside GitHub and Bitbucket. Reuse
`Igne\LaravelBootUp\Pipelines\CiScripts` to ship the same shared scripts, and
`Igne\LaravelBootUp\Data\Lines` to build documents.
