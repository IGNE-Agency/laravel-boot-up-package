# Boot-up whole-tree refactor: config layout, workers, serve lifecycle

- **Type:** refactor
- **Date:** 2026-07-29
- **Status:** accepted

## Goal

Realign the package's internals so that each configuration object owns exactly one section of the published config file, every long-running process is modelled the same way, the serve lifecycle lives in one class instead of a ten-collaborator command method, and the README stops duplicating `docs/`. Along the way, fix two real signal-handling bugs and close the correctness gaps the investigation surfaced.

## Context & problem

The package is **unreleased with zero users**. There is no backwards compatibility to preserve, no migration path to document, and nothing anywhere may say that behaviour "changed", is "deprecated", or was "previously" something else. Config keys, driver keys and class names are free to move.

Concrete problems, all verified against the tree at `074d0b3`:

- **`ServersConfig` holds 11 properties spanning three mutually exclusive drivers** — 5 shared, 4 Herd-only, 2 Artisan-only, 0 Sail. `HerdServer` receives `artisanPort`; `ArtisanServer` receives the Herd health settings. Sail is worse: its one flag lives in `EnvironmentConfig`, and its two timeouts are hardcoded at [SailServer.php:31](src/Servers/Sail/SailServer.php#L31) and [Docker.php:19](src/Servers/Sail/Docker.php#L19) where nothing can reach them, because neither class is bound in the provider.
- **Config keys and the classes that read them have drifted apart.** `shutdown.*` is read by `ServersConfig`, top-level `migrations.auto` by `DatabaseConfig`, `browser.open` and `auto_accept` by `ServeConfig`. "Which class owns this key" is unanswerable from either side.
- **Defaults are written twice** — once in a constructor signature, once as the second argument to `$config->get()` — and four classes (`FrontendConfig`, `ServeConfig`, `ToolsConfig`, `DeployConfig`) have no constructor defaults at all.
- **The five worker-launching steps are near-duplicates.** `StartHorizon` and `StartReverb` are line-for-line identical modulo three literals. `WorkerDefinition` carries `options`, used by one worker, and `streamAs`, used by two.
- **`BuildOrWatchAssets` is three behaviours behind one config key** — skip, synchronous build, worker launch.
- **`ServeCommand::handle()` takes ten injected collaborators** and coordinates five phases; `StepSequence` is 326 lines holding two twenty-entry class-string maps that must be hand-edited per step, with third-party steps falling through to `"unknown-{$index}"`.
- **Two live signal bugs.** `Laravel\Prompts\Progress::finish()` calls `resetSignals()`, which forces `SIGINT` to `SIG_DFL`; [ServeCommand.php:82](src/Console/ServeCommand.php#L82) calls `$reporter->finish()` three lines before entering the stream loop, so during the phase whose banner reads "press Ctrl+C to stop everything" the trap is disarmed and Ctrl+C kills the process with no teardown. Separately, prompts set `-isig`, so Ctrl+C at the stop-server confirm calls `exit(1)` *inside* `tearDown()`'s `try` — and `exit()` skips `finally`, so `store->clear()` and the `public/hot` cleanup never run. Neither is caught by any of the 702 tests, because `trap()` is a hard no-op under Pest.
- **README is 261 lines**, roughly 86–242 duplicating `docs/`; `process.terminal_pid_timeout`, `auto_accept`, `--detach` and `--yes` are documented nowhere.

Binding constraint throughout: `tests/ArchTest.php` (19 rules) — `final` everywhere except three named classes, `Config` namespace must be `final readonly` + `*Config` + `fromRepository`, the `Config` suffix is reserved to it, only traits in `Concerns`, only enums in `Enums`, only interfaces in `Contracts`, `Data` readonly.

## Decision

Six coordinated changes, in dependency order:

1. **One config class per top-level key.** 17 classes; `ServersConfig` and `WorkersConfig` are deleted and their properties redistributed. Constructors keep their defaults *and* `fromRepository` keeps `$config->get($key, $default)` — because `ServiceProvider::mergeConfigFrom` is a shallow `array_merge` ([ServiceProvider.php:163](vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php#L163)), so an app that publishes and trims the config loses whole nested subtrees and a constructor without a default would fatal. Three generic tests police agreement.
2. **The worker is the step**, via a `Concerns\LaunchesAsWorker` trait rather than an abstract base — this satisfies the composition preference *and* keeps the `final`-everywhere arch rule at three exceptions. `WorkerDefinition` is deleted; workers expose their own description through a `Contracts\Worker` interface.
3. **`BuildOrWatchAssets` splits** into `BuildAssets` (plain step, synchronous) and `WatchAssets` (worker), each with its own mode gate.
4. **`Serve\ServeRunner` owns the serve lifecycle**; `ServeCommand` maps flags, runs the confirm gate, delegates. The two signal bugs are fixed *first*, as a standalone change.
5. **Three new enums** — `AssetMode`, `DeployPhase`, `OperatingSystem` — plus `ServeStage` backing values becoming slugs with a `label()`. Enum branching is identity and `match`, never per-case `is*()`; grouped predicates only where a grouping is real.
6. **Step metadata moves to attributes**; the `STAGES`/`GROUPS` const maps are deleted.

Plus the mechanical sweeps: typed constants, interpolation where operands already permit it, DTOs for internal records, duplication extraction, and the documentation rewrite.

## Alternatives considered

- **Per-case `is{Value}()` methods on every enum** (the original request). Dropped: PHPStan cannot narrow `$this` from a boolean method, so call sites gain no type information, and adding a case silently returns `false` everywhere instead of failing an exhaustive `match`. laravel/framework has four enums with zero methods; Filament has one `is*()` across ~36 enums.
- **Published file as the sole source of defaults, constructors requiring every property.** Chosen first, then refuted: reproduced live as a `TypeError` when an app trims its published config, and it breaks the documented additive-drivers behaviour that [ServerSelectorTest.php:85](tests/Feature/Servers/ServerSelectorTest.php#L85) pins.
- **Seven more enums** — `ProgressState`, `TerminalColor`, `LineKind`, `PipelineJob`, `StepGroup`, `DatabaseDriver`. All dropped. `Prompt::$state` is an inherited `public string` and PHP forbids retyping it. The palette strings are *method names* invoked as `terminal()->{$color}()`. `LineKind` and `PipelineJob` remove zero literal comparisons. An enum cannot be an array key, which `StepGroup` requires. `DatabaseDriver` is built from a value the package does not own, and `EnsureDatabaseCredentials::driver()` actually returns a *connection name*.
- **Abstract `Worker` base class.** Rejected in favour of the trait: an abstract class is not `final` and would need a fourth arch-rule exception.
- **Keeping `WorkerDefinition`.** Considered — it guarantees the launcher gets one immutable snapshot. Rejected; the launcher reads `label()` and `command()` into locals once at the top of `launch()`, which removes the drift risk without the DTO.
- **Adding `BuildAssets` to `deploy_steps`.** Rejected: with the default `assets => watch` a mode-gated build would skip anyway, and the generated deploy scripts — what actually runs on a server — already build.
- **A custom recursive config merge** so the published file could be authoritative. Rejected: bespoke merge semantics diverging from every other Laravel package, and `array_replace_recursive` merges lists by index, which would make removing a step from `serve_steps` impossible.

## Approach / steps

Seven workstreams. **A must land first** — it is a standalone bug fix, and D depends on it. **B and E are independent of each other** and can run concurrently. **C depends on both B and E** (per-worker configs from B, `AssetMode` and the step-metadata attributes from E). **D depends on A.** **F is independent** and can run at any point. **G depends on B and C** (final key names and step lists).

Shared contracts every stream must agree on, fixed up front:

- Config class names and their owned keys (table in stream B).
- `Contracts\Worker`: `label(): string`, `name(): string`, `command(): CommandLine`, `runIn(): RunMode`, `streamName(): string`.
- `WorkerLauncher::launch(Worker $worker, ServeContext $context): ?ProcessRecord`.
- Worker classes stay inside the existing `*\Steps` namespaces, so no arch-rule namespace list needs editing.

---

### Stream A — signal fixes (do first, alone, in its own commit)

1. [TrackedProgress.php](src/Services/TrackedProgress.php) — add `protected function resetSignals(): void {}` with a one-line comment that `Progress` forces `SIGINT` to `SIG_DFL` and the command's trap owns it. One method covers `finish()`, both `settle()` paths and the inherited `map()` catch; do **not** inline `finish()`'s body.
2. [ServeCommand.php](src/Console/ServeCommand.php) — add a `tearingDown` guard as the first statement of the trap closure so repeated signals return instead of re-entering and truncating the in-flight teardown with `exit(0)`.
3. [ShutdownRunner.php](src/Serve/ShutdownRunner.php) — `exit()` does not run `finally`, so move `store->clear()` and `cleanUpStaleHotFile()` to immediately after `reapAll()` and *before* the stop-server prompt. `stopServer()` already receives the key and `startedByUs` flag as arguments, so it needs nothing from the store. Outcome is unchanged when teardown completes normally, and correct when the user presses Ctrl+C at the prompt.
4. New test: capture `pcntl_signal_get_handler(SIGINT)`, run `terminal()->progress(...)->start()` then `finish()`, assert the handler is not `SIG_DFL`, and restore it so the suite does not leak a handler. Repeat for `fail()` and `interrupt()`. This is the whole observable surface — `trap()` itself is inert under Pest.
5. Keep the ordering comment at `ServeCommand.php:115-120`. `Progress::start()` still clobbers `SIGINT`, so trap registration must stay between `begin()` and the pipeline.

### Stream B — config layer

| Key | Class | Properties |
|---|---|---|
| `server.*` | `DevServerConfig` | default, prompt, drivers |
| `herd.*` | `HerdConfig` | site, health.attempts, health.delay_ms, health.timeout_seconds |
| `artisan.*` | `ArtisanServeConfig` | host, port |
| `sail.*` | `SailConfig` | manage_alias, ready_timeout_seconds, docker.start_timeout_seconds |
| `shutdown.*` | `ShutdownConfig` | prompt_stop_server, stop_server_by_default |
| `queue.*` | `QueueConfig` | enabled, run_in, flags |
| `horizon.*` | `HorizonConfig` | enabled, run_in |
| `reverb.*` | `ReverbConfig` | enabled, run_in |
| `scheduler.*` | `SchedulerConfig` | enabled, run_in |
| `frontend.*` | `FrontendConfig` | package_manager, assets, watch_in |
| `database.*` | `DatabaseConfig` | create, prompt_missing_credentials, reconcile_credentials, migrations.auto |
| `tools.*` | `ToolsConfig` | auto_install, auto_update, required, installers |
| `serve.*` | `ServeConfig` | steps, open_browser, auto_accept |
| `deploy.*` | `DeployConfig` | cache_framework_files, finalize, script_generators, steps, auto_accept |
| `pipeline.*` | `PipelineConfig` | branches, generators, composer_auth, steps, files |
| `environment.*` | `EnvironmentConfig` | allowed |
| `process.*` | `ProcessConfig` | terminal_pid_timeout |

`DevServerConfig` is named to stay unmistakable beside `ServeConfig`; `ArtisanServeConfig` mirrors `ArtisanServer` rather than reading as "config for artisan commands".

1. Restructure [config/boot-up.php](config/boot-up.php) to the table. `auto_accept` is deliberately two keys so `app:serve` and `app:deploy` can differ.
2. Write the 9 new classes — `DevServerConfig`, `HerdConfig`, `ArtisanServeConfig`, `SailConfig`, `ShutdownConfig`, `HorizonConfig`, `ReverbConfig`, `SchedulerConfig`, `ProcessConfig` — delete `ServersConfig` and `WorkersConfig`, and give every constructor parameter its default — **except** `serve.steps` and `deploy.steps`, which keep `[]`; honouring the rule there would mean hard-coding the 21-entry and 10-entry step lists in PHP beside the identical lists in the config file.
3. Preserve `BUILT_IN_DRIVERS` merge semantics exactly — code defaults merged *under* the config value, so project drivers stay additive.
4. Do **not** reorder `FrontendConfig`'s parameters; four test sites construct it positionally.
5. Constructors, corrected against what each class actually reads:
   - `HerdServer`: `ProcessRunner, HerdServices, HerdSites, HerdConfig, ?string $projectPath = null`
   - `ArtisanServer`: `ProcessRunner, WorkerLauncher, ArtisanServeConfig, CombinedRunPlan` (properties renamed `host`/`port`)
   - `SailServer`: keeps Laravel's `Repository` **and** gains `SailConfig`; rename the property to `$laravelConfig`. A naive swap fatals at serve time on `config('app.url')` in `url()`.
   - `Docker`: takes `SailConfig`. Without this the two new `sail.*` timeout keys are inert, since neither `Docker` nor `SailServer` is bound in the provider.
   - `ServerSelector` → `DevServerConfig`; `StopServerPrompt` → `ShutdownConfig`; `SailAliasInstaller` → `SailConfig`; `HerdServices` → `HerdConfig`.
   - `DeployCommand` switches from `ServeConfig` to `DeployConfig`.
6. [BootUpServiceProvider.php](src/Providers/BootUpServiceProvider.php) — `CONFIG_CLASSES` grows to 17; the raw `boot-up.process.terminal_pid_timeout` read at line 129 becomes `ProcessConfig`, removing the last raw `boot-up.*` read outside `src/Config/`; **delete** the `HerdServices` singleton closure entirely and let autowiring bind it.
7. Three generic tests, globbed over `src/Config/*Config.php` so no property name is written by hand:
   - `fromRepository(new Repository([]))` equals `new X` — catches drift between the `get()` fallback and the constructor default.
   - `fromRepository(new Repository(['boot-up' => $published]))` equals `new X` — the published file agrees with the constructors. Exclude `drivers` (the published value is not authoritative under `array_merge`) and the two step lists; skip when any `BOOT_UP_*` env var is set.
   - Every leaf path in the published file is read by some `fromRepository` — the only one of the three that catches a typo'd key.
   - Plus: the step lists are non-empty and every entry implements `Contracts\Step`; and `serve.auto_accept` and `deploy.auto_accept` are independent.
8. Sweep hardcoded key strings: [EnvironmentException.php:26](src/Exceptions/EnvironmentException.php#L26), [ProvidesDeployTasks.php:18](src/Contracts/ProvidesDeployTasks.php#L18).

### Stream C — workers and assets

1. `Contracts\Worker` as fixed above. `options` disappears as a concept: `QueueWorker` applies its flags inside `command()` via the existing `CommandLine::withOptions()`.
2. `Concerns\LaunchesAsWorker` — supplies `handle(ServeContext, Closure): mixed` doing gate→launch, `shouldRun(ServeContext): bool` defaulting to `true` (silent by default, matching the documented detect-and-skip behaviour of Horizon/Reverb/Scheduler), `streamName()` defaulting to `label()`, and an abstract `launcher(): WorkerLauncher` accessor — PHP cannot require a promoted property from a trait. A worker that wants to explain itself prints its own note and returns `false`.
3. Rename in place, keeping the existing namespaces: `Queue\Steps\QueueWorker`, `Workers\Steps\{HorizonWorker,ReverbWorker,SchedulerWorker}`. Each is `final`, implements `Step`, uses the trait.
4. `WorkerLauncher::launch()` reads `$label = $worker->label()` and `$command = $worker->command()` into locals once at the top. Delete [WorkerDefinition.php](src/Data/WorkerDefinition.php). Keep the label-string `isRunning()`/`stop()` API — `ArtisanServer` uses only those.
5. New `HorizonPresence` collaborator (`HorizonConfig` + `ComposerJson`), consulted by both `QueueWorker` and `HorizonWorker`. It *replaces* two dependencies in each rather than adding one, and removes the byte-identical duplicated predicate.
6. `QueueWorker` memoises its resolved queue connection — it derives both command tokens and display name from it.
7. Split the asset step into `Frontend\Steps\BuildAssets` (plain `Step`, synchronous, keeps `ProcessRunner` + `CommandRewriter`, owns the no-`build`-script gate) and `Frontend\Steps\WatchAssets` (worker, owns the no-`dev`-script gate). The three shared gates — `--without-assets`, `AssetMode::Skip`, no `package.json` — go in a `Concerns` trait both use. **Each also needs its own mode gate**: `BuildAssets` runs iff mode is `Build`, `WatchAssets` iff `Watch`. Without that both run in every mode.
8. Replace [config/boot-up.php:299](config/boot-up.php#L299) with the two classes **in place**, before `AnnounceApplication`. Leave `deploy_steps` untouched.
9. Give **both** classes a `#[Stage]` and a `#[Group('assets')]` attribute (stream E has already replaced the const maps by this point) and add both to `StepSequence::label()`. Missing either one makes that class inherit the previous stage and emit an un-gated second summary line. Progress total goes 21 → 22.
10. Point `ShutdownRunner::ASSET_WATCHER_LABEL` at `WatchAssets::LABEL` directly rather than mirroring the string, and assert the equality in a test.

### Stream D — serve lifecycle (after A)

1. `Serve\ServeRunner` with exactly two public entry points plus a failure hook:
   - `prepare(ServeOptions $options, ?string $server): StepSequence` — single-instance guard (keeping its current warning wording), reaper prune, `ServerSelector::select()`, context, plan.
   - `run(Closure $trapUsing): int` — `begin` → trap → pipeline → `finish` → stream → shutdown, sealed. The closure is a **registrar** (`fn ($signals, $handler) => $this->trap($signals, $handler)`), not a ready-made handler, because the handler must close over runner-owned state.
   - `fail(): void` for the progress bar.
   Do not expose `begin()` or the pipeline separately — that is the only way the trap can end up registered after the pipeline starts, which would orphan the write-ahead `ActiveServerRecord`.
2. `ServeCommand::handle()` stores the method-injected runner as its first statement (as it already does for `$reporter`) so `onFailure()` reaches the live instance — `onFailure()` fires from `GuardsAgainstFailures` *outside* `handle()`, and re-resolving would produce a fresh runner with a fresh unbound reporter. Call `$this->runner?->fail()`.
3. `run()` owns its endings; the streaming path already prints "Application ready." before streaming, so the command must not append it again.
4. The runner does not touch `Illuminate\Console\Command`, so it cannot use `self::SUCCESS`; return plain ints.

### Stream E — enums and mechanical sweeps

1. `Enums\AssetMode` (watch/build/skip), `Enums\DeployPhase` (before-deploy/before/after/after-deploy), `Enums\OperatingSystem` (all six `PHP_OS_FAMILY` values, strict `from()`).
2. New `Exceptions\ConfigException` wrapping strict enum parsing so a typo names the key and the legal values instead of raising a bare `ValueError`. `RunMode::fromConfig()` becomes strict via the same helper; empty string and `null` mean "use the default", not "invalid".
3. `DeployPhase` conversion happens *inside* `RunDeployTasks`; its signature keeps `string $phase = 'before'`, because the value arrives spread from `StepDescriptor::$parameters`. Use `tryFrom` plus an explicit throw so the existing actionable message survives. Same at `StepSequence.php:293`.
4. `Platform` takes `?OperatingSystem $family = null` defaulting to `?? OperatingSystem::from(PHP_OS_FAMILY)` — an enum `from()` call is not a valid constant expression. Keep the three `is*()` methods as the public facade surface.
5. `ServeStage` backing values become slugs; add `label()`; change [StageReporter.php:52](src/Serve/StageReporter.php#L52) from `->value` to `->label()` in the same commit, and update the three test assertions. Same rule applied to the one enum whose labels currently live outside it: move the status→wording `match` out of [ToolOutcome.php:26-30](src/Data/ToolOutcome.php#L26) and into `ToolStatus::label()`, leaving `ToolOutcome::describe()` to compose the label with the version.
6. `ProcessRecord::$mode` becomes `?RunMode`, with `->value` at the `toArray()` boundary — it currently compares `=== 'combined'` while `RunMode::Combined` already exists.
7. Rename `EnsureDatabaseCredentials::driver()` to `connection()` and its `$driver` locals to `$connection`. It returns `DB_CONNECTION` and is used as a connection name in `config()->set("database.connections.{$x}...")` and `DB::purge($x)`; calling it a driver is the actual defect the database enum exercise uncovered.
8. `Attributes\Stage(ServeStage)` and `Attributes\Group(string)`; delete the `STAGES` and `GROUPS` const maps. **The stage fallback is `null`, not `Custom`** — `?ServeStage` from reflection, then `?? $stage ?? ServeStage::Custom`, so a custom step keeps inheriting the stage it is slotted into. Attribute-less classes keep a per-index-unique group key so multiple custom steps do not collapse into one summary line. Guard `for()` with `class_exists()`, since reflection on a bad class-string throws where array lookups did not. **Labels stay in `StepSequence`** — four of twenty are computed from `ServeOptions` or step parameters, which a class-level attribute cannot express.
9. Add an arch rule for the new `Attributes` namespace.
10. Type all 32 untyped constants in `src/` and the 3 in `tests/`.
11. Interpolate only where operands already permit it — roughly half the 26 concatenation sites cannot be (`__DIR__`, `PHP_EOL`, class constants, function-call and ternary operands).
12. DTOs for internal records: `Data\DatabaseConnection` (the six-key array threaded through five `DatabaseCreator` methods) and `Data\DatabaseCredentials` (the `DB_*` map threaded through five `EnsureDatabaseCredentials` methods). Parameter objects for the six-parameter `checkJob` and the five-parameter `stepDefinition`, `validate` and `parseStep`. Arrays stay at every external boundary — `PackageJson`, `ComposerJson`, raw pipeline config, and the JSON `array{}` shapes.
13. Remove the three version-difference traces: the `'laravel'`-for-BC docblock and key on `ArtisanServer` (becomes `'artisan'`, including the hardcoded fallback at [ServerSelector.php:34](src/Servers/ServerSelector.php#L34)), the arch rule named "legacy namespaces are gone for good" (rename to describe the constraint, not a history), and the `ProcessRecord` test for pre-`mode` ledger entries. Add the `try/catch (Throwable)` that `StatusCommand` already has around `ShutdownRunner::stopServer()`'s `driver()` call — an unknown persisted key is an unhandled crash today.

### Stream F — duplication extraction (cheapest correct tool per cluster)

Collaborators where the duplication is behaviour with state:

- `JsonStore` absorbing the whole read-decode-validate-quarantine-atomically-write shape shared by `ProcessLedger` and `ActiveServerStore` — the two byte-identical `quarantine()` methods are only the last line of it.
- A shared pattern-matching detector for `LockfileConflictDetector` and `SailUpFailureDetector`.
- `ScriptHeader` for the two byte-identical bash-header builders in `CiScripts` and `GitHooks`.
- A terminal-command formatter for the identical `cd && command` prologue in both terminal launchers.
- A shared generator-registry helper for the byte-identical maps in `PipelineCommand` and `DeployScriptCommand`.

Traits where it is a repeated phrase with no state: the step skip prologues, the rewrite-then-run sequence, `outputOf()` (three copies, two byte-identical). Private-method extraction, not reuse machinery, for `Terminal`'s three block renderers and the `mkdir` guard.

### Stream G — documentation (last)

1. README → landing page, ~60 lines: title, install, quickstart, the seven commands with one line each, docs index, testing, licence. Point at `php artisan list` and `php artisan app:serve --help` as the authoritative flag reference.
2. New `docs/COMMANDS.md` with full signatures and option tables, including `app:status` and `app:down` (today README-only) and the missing `--detach` and `--yes`.
3. Restructure `docs/CONFIGURATION.md` to one section per config class, matching stream B's table, with anchors every README pointer can target. Add the undocumented `auto_accept` (both), `process.terminal_pid_timeout` and the two new `sail.*` keys.
4. Update the six `serve_steps`/`deploy_steps`/`environments` references across `docs/EXTENDING.md`, `docs/CUSTOM_COMMANDS.md` and `docs/CONFIGURATION.md`.

## Research findings that drove this

- **`mergeConfigFrom` is shallow** ([ServiceProvider.php:163](vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php#L163)) — a plain `array_merge`, depth 1. Verified live: an app trimming to `'server' => ['default' => 'herd']` silently loses ten leaves. This single fact is why constructors keep their defaults. Laravel itself concedes shallow is insufficient — `LoadConfiguration` re-merges a hand-written allow-list that applies only to framework config.
- **Enum cases are `config:cache`-safe.** `var_export` emits `\FQCN::Case` and round-trips; plain objects need `__set_state` and closures fatal. Verified on PHP 8.4.
- **`Prompt::$state` is an inherited `public string`** ([Prompt.php:27](vendor/laravel/prompts/src/Prompt.php#L27)) and PHP property types are invariant on inheritance — proven by running it. That kills `ProgressState` outright.
- **`resetSignals()` saves no prior handler**; its only saved state is `$originalAsync`, and restoring it is a no-op because Symfony's `SignalRegistry` constructor already set `pcntl_async_signals(true)`. So neutering it leaks nothing.
- **Prompts set `-isig`** ([Prompt.php:120](vendor/laravel/prompts/src/Prompt.php#L120)), so Ctrl+C during a prompt is `Key::CTRL_C` and `exit(1)`, never a signal — which is why the teardown `finally` is skipped and why no prompt ever installs a competing SIGINT handler.
- **Prior art on enum helpers is unanimous**: laravel/framework has four enums with zero methods; Filament has one `is*()` across ~36; `spatie/laravel-data`'s 13-case `DataTypeKind` has one identity predicate and four group predicates. The packages that exist to add `is()`/`in()` helpers do so precisely to stop people hand-writing one method per case.
- **`spatie/laravel-backup` v9's config-DTO migration** is the cautionary case for the rejected defaults approach — issues #1813, #1823, #1866, #1867, #1871: fifteen previously-optional keys became mandatory, `composer require` broke, runtime `config()->set()` stopped working, and key paths shifted in a patch release.
- **`Illuminate\Support\Manager` and every first-party manager take `array $config`** — there is no typed driver-config object anywhere in Illuminate. The per-driver split here is justified by this package's own arch rules, not by framework precedent.
- **Pest passes silently on empty arch layers** (`Blueprint::targeted()` iterates zero objects), which is why worker classes stay inside their existing `*\Steps` namespaces rather than moving up — moving them would make two arch rules vacuous with no red test.

## Verification

- `vendor/bin/pest` — 702 tests today. Expect roughly 600–700 lines of test churn: 5 files substantially rewritten (`ServersConfigTest`, `ShutdownRunnerTest`'s helper, `WorkerStepsTest`'s `bindWorkerDeps`, `SailServerTest`'s factory, `EnvironmentConfigTest`), 5 mechanically edited across 16 construction sites, 18 config-key strings in `ServeCommandTest` and `DeployCommandTest`, and ~11 new `tests/Unit/Config/` files — `FrontendConfigTest` and `WorkersConfigTest` do not exist today.
- `vendor/bin/pest tests/ArchTest.php` — 19 rules, all passing now; expect 20 after the `Attributes` rule. No rule may be weakened; the `final`-everywhere exception list must still hold exactly three entries.
- `vendor/bin/pint --test`.
- Manual smoke, per server driver: `php artisan app:serve` and check the plan summary lists 22 steps with one assets line; **Ctrl+C during the combined-output stream** and confirm teardown runs, `active-server.json` is gone and `public/hot` is cleaned; then `app:status` and `app:down` from a second terminal. Repeat with `--detach`, with `--without-assets`, and with `frontend.assets` set to each of the three modes.
- `php artisan app:deploy`, `generate:deploy-script`, `generate:pipeline`, `generate:git-hooks` — all four read config classes that move.
- Publish the config, trim it to a single key, and boot: every config class must still construct. This is the test the suite lacks entirely today.

## Risks & open questions

- **Stream D is the highest-risk change.** The phase ordering in `handle()` is load-bearing in non-obvious ways — write-ahead record before `start()`, trap after `begin()`, reporter settled before the stream loop — and it has **zero** test coverage: all 23 `ServeCommandTest` tests resolve `follow` to `false` because stdout is a pipe, so `streamCombinedOutput()` is never entered, and `trap()` is inert under Pest. Land stream A separately and first so the signal behaviour is known-good before the lifecycle moves.
- **The signal fix is only partly testable.** The new test covers `pcntl_signal_get_handler` after `finish()`; the end-to-end "Ctrl+C during streaming tears down cleanly" path can only be verified by hand.
- **A stale prompts closure remains in the SIGINT chain.** With `resetSignals()` neutered, the cancel closure installed by `Progress::start()` stays registered for the rest of the process. Harmless only because the trap handler `exit()`s first — if `ServeRunner` ever stops exiting from the handler, that closure will render a cancel frame over finished output and exit unexpectedly. Document it at the override.
- **Pre-existing, not fixed here:** `exit()` from the trap handler skips `Command::execute()`'s `finally`, so a Ctrl+C'd `app:serve --isolated` holds its isolation lock until the TTL expires. Recorded rather than addressed; fixing it means not exiting from the handler, which conflicts with the point above.
- **`ArtisanServeConfig` owns `artisan.*`**, so the class name carries "serve" and the key does not. Deliberate: the key sits beside `herd.*` and `sail.*`, where it reads as a driver name, while the class name has to distinguish itself from the seven artisan commands in `src/Console/`.
- **`deploy_steps` still installs frontend dependencies without building them.** Deliberately left alone; the generated deploy scripts build correctly, so this only affects using `app:deploy` as a local rehearsal.
- The Sail timeout keys are new configuration surface, so `SailConfig`'s two timeout properties will be carried by `SailAliasInstaller`, which never reads them — the accepted cost of one class per key.
