# Extending the package

Six extension points, none of which require touching package code.

## Project commands

Generators and warmers that run across four deploy phases (`beforeDeploy`,
`beforeMigrations`, `afterMigrations`, `afterDeploy`) during `app:serve` /
`app:deploy` and get embedded in exported deployment scripts.

1. Implement `Igne\LaravelBootUp\Deploy\ProvidesProjectCommands` (all four
   methods; return `[]` for phases you don't use).
2. Bind it as a singleton in your `AppServiceProvider::register()`.

Full guide: [CUSTOM_COMMANDS.md](CUSTOM_COMMANDS.md) — example:
[examples/ProjectCommands.php](../examples/ProjectCommands.php).

## Custom pipeline steps

1. Implement `Igne\LaravelBootUp\Serve\Step`.
2. Insert the class anywhere in the published `boot-up.serve_steps` /
   `boot-up.deploy_steps` arrays.

Print through the package's terminal for consistent styling: the global
`terminal()` helper (no import needed — `terminal()->success('Done.')`) or the
`Igne\LaravelBootUp\Facades\Terminal` facade; both resolve the same singleton,
so your output plays nicely with the `app:serve` progress bar. A
`Igne\LaravelBootUp\Facades\Platform` facade (`Platform::isWindows()`) exists
too.

## Custom servers

1. Implement `Igne\LaravelBootUp\Servers\Server`.
2. Register it under `boot-up.server.drivers`, e.g.
   `'valet' => ValetServer::class`.

It becomes selectable by argument, config, and prompt, and participates in state
tracking, shutdown, and command rewriting automatically.

The interface declares three capability methods you must implement:

- `providesDatabase()` — the server provisions the database itself, so creation
  is skipped.
- `databaseReachableFromHost()` — return `false` to route database checks and
  migrations through your command rewrites (like Sail does).
- `stopImpact()` — a non-null warning makes stopping require an explicit yes
  (like Herd's machine-wide stop).

## Custom tools

1. Implement `Igne\LaravelBootUp\Tools\InstallsTool`.
2. Map it under `boot-up.tools.installers` with a version constraint under
   `boot-up.tools.required`.

Config wins on key collision, so you can also replace a built-in installer (e.g.
install Node via nvm).

## Custom deployment platforms

1. Implement `Igne\LaravelBootUp\Deploy\Scripts\ScriptGenerator`.
2. Register it under `boot-up.deploy.script_generators`, e.g.
   `'envoyer' => EnvoyerScriptGenerator::class`.

It becomes selectable in `app:deploy-script` alongside Forge and fortrabbit.

## Custom git providers

1. Implement `Igne\LaravelBootUp\Pipelines\PipelineGenerator` — `files()`
   returns the `GeneratedFile`s to write, `secrets()` the instructions-table
   rows and their detail sections, and `anchors()` the job names project config
   may inject extra steps into (see
   [Extending the pipeline](PIPELINES.md#extending-the-pipeline)).
2. Register it under `boot-up.pipeline.generators`, e.g.
   `'gitlab' => GitlabPipelineGenerator::class`.

It becomes selectable in `app:pipeline` alongside GitHub and Bitbucket. Reuse
`Igne\LaravelBootUp\Pipelines\CiScripts` to ship the same shared scripts, and
`Igne\LaravelBootUp\Support\Lines` to build documents.
