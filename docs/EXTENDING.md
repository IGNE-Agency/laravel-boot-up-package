# Extending the package

Seven extension points, none of which require touching package code.

## Registered dev processes

Extra long-running processes for `app:serve` — the package's take on
Laravel's `php artisan dev` / `DevCommands`. Register from any service
provider's `boot()`:

```php
use Igne\LaravelBootUp\Facades\BootCommands;

BootCommands::artisan('reverb:start', 'reverb')->orange();
BootCommands::register('stripe listen --forward-to '.config('app.url'));
BootCommands::packageManager('run dev')->after('queue');
BootCommands::packageManagerExec('vite --port 3000');
```

`register()` runs the command as written; `artisan()` prefixes `php artisan`;
`packageManager()` prefixes the project's package manager binary
(`packageManager('run dev')` becomes `bun run dev` — the same contract as
`DeployTask::packageManager()`); `packageManagerExec()` prefixes the
manager's exec runner (`bunx` / `npx` / `pnpm exec` / `yarn exec`).

The optional second argument names the process; otherwise the command's
first token is the name (`packageManager('run <script>')` names itself after
the script). The name is the ledger label, the `[prefix]` in the combined
stream, and the slot the replacement and filter semantics below act on.

Each registration returns a fluent object:

- `->blue()` `->purple()` `->pink()` `->orange()` `->green()` `->yellow()`,
  or `->color($streamColor)` — pick the stream prefix color
  (`Enums\StreamColor`); processes without one draw the unused palette
  colors in stream order.
- `->inTerminal()` / `->inBackground()` — run in an own terminal window /
  detached with a log file, instead of the combined stream.
- `->env([...])`, `->in($directory)` — extra environment variables / working
  directory.
- `->first()`, `->last()`, `->before('vite')`, `->after('queue')` — position
  in the combined output stream. Built-in stream names: `server`, `queue`,
  `horizon`, `reverb`, `scheduler`, `vite`. The last call wins; unknown or
  absent targets are ignored.

Semantics worth knowing:

- **Replacing a built-in** — registering under a built-in worker's stream
  name (`queue`, `horizon`, `reverb`, `scheduler`, `vite`) replaces it
  entirely: the built-in's config keys no longer apply, the registration's
  own run mode/color/placement govern, and it inherits the built-in's slot
  in the stream. The name `server` is reserved for the development server.
- **Filters** — `BootCommands::only('queue', 'stripe')` and
  `BootCommands::except('scheduler')` filter built-ins and registrations
  alike; calls merge.
- **Priority** — registrations from application code always beat a vendor
  package's registration under the same name; a vendor package can suggest a
  process but never silently override yours.
- Registered processes launch in the services stage of the boot, are
  ledger-tracked (`app:status` sees them, `app:down` stops them), get server
  command rewrites (Sail), and degrade to background processes under
  `--detach` or a non-interactive stdout — exactly like the built-ins.

## Project commands

Generators and warmers that run across four deploy phases (`beforeDeploy`,
`beforeMigrations`, `afterMigrations`, `afterDeploy`) during `app:serve` /
`app:deploy` and get embedded in exported deployment scripts.

1. Implement `Igne\LaravelBootUp\Contracts\ProvidesDeployTasks` (all four
   methods; return `[]` for phases you don't use).
2. Bind it as a singleton in your `AppServiceProvider::register()`.

Full guide: [CUSTOM_COMMANDS.md](CUSTOM_COMMANDS.md) — example:
[examples/DeployTasks.php](../examples/DeployTasks.php).

## Custom pipeline steps

1. Implement `Igne\LaravelBootUp\Contracts\Step`.
2. Insert the class anywhere in the published    `boot-up.serve.steps` / `boot-up.deploy.steps` arrays.

Print through the package's terminal for consistent styling: the global
`terminal()` helper (no import needed — `terminal()->success('Done.')`) or the
`Igne\LaravelBootUp\Facades\Terminal` facade; both resolve the same singleton,
so your output plays nicely with the `app:serve` progress bar. A
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
