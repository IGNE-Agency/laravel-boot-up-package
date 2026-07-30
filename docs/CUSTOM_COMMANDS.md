# Project commands

Hook your project's own commands into the boot/deploy flow — code generators,
schema exporters, cache warmers — without touching the package.

## How it works

1. Create a class implementing
   `Igne\LaravelBootUp\Contracts\ProvidesDeployTasks` (see
   [examples/DeployTasks.php](../examples/DeployTasks.php)):

```php
namespace App\BootUp;

use Igne\LaravelBootUp\Data\DeployTask;
use Igne\LaravelBootUp\Contracts\ProvidesDeployTasks;

final class DeployTasks implements ProvidesDeployTasks
{
    public function beforeDeploy(): array
    {
        return []; // earliest hook — return [] if unused
    }

    public function beforeMigrations(): array
    {
        return [
            DeployTask::artisan('wayfinder:generate --path=resources/js/wayfinder', 'Generating routes...'),
        ];
    }

    public function afterMigrations(): array
    {
        return [
            DeployTask::artisan('model:typer', 'Generating model types...'),
            DeployTask::packageManager('run zodgen', 'Generating Zod schemas...'),
        ];
    }

    public function afterDeploy(): array
    {
        return []; // latest hook, after the release is live — return [] if unused
    }
}
```

All four methods are required; return an empty array for any phase you don't
use.

1. Bind it as a singleton in your `AppServiceProvider::register()`:

```php
$this->app->singleton(
    \Igne\LaravelBootUp\Contracts\ProvidesDeployTasks::class,
    \App\BootUp\DeployTasks::class,
);
```

That's it. `app:serve` and `app:deploy` resolve the binding lazily — no binding
means no project commands, no error.

## Command types

| Named constructor                                      | Runs as                                              |
| ------------------------------------------------------ | ---------------------------------------------------- |
| `DeployTask::artisan('scout:sync')`                | `php artisan scout:sync`                             |
| `DeployTask::composer('dump-autoload --optimize')` | `composer dump-autoload --optimize`                  |
| `DeployTask::packageManager('run build')`          | `bun run build` (or your configured package manager) |

The optional second argument is a human-readable message printed before the
command runs.

## Execution rules

- **Server-aware**: commands are rewritten for the active server — under Sail,
  `php artisan scout:sync` runs as `./vendor/bin/sail artisan scout:sync`.
- **Streamed**: output is streamed live to your terminal.
- **Fail fast**: a non-zero exit code aborts the whole boot. Fix the command or
  remove it; nothing downstream runs on a broken state.
- **No shell**: commands execute as plain argument lists. Shell metacharacters
  (`;`, `&&`, `|`, backticks, redirection) are rejected at construction time, as
  are destructive words (`rm`, `sudo`, `kill`, ...). Word-boundary matching
  means `confirm:users` is fine while `rm -rf` is not.

## When they run

Four phases, always in this order:

```text
composer install          (deploy step)
frontend install          (deploy step)
→ beforeDeploy()          earliest — schema-independent, before optimize
optimize / finalize
→ beforeMigrations()
migrations
→ afterMigrations()
→ afterDeploy()           latest — after the release is finalized/live
queue restart
```

The local `app:serve` / `app:deploy` pipeline runs the two migration phases by
default. To run the deploy phases locally too, add
`RunDeployTasks::class.':before-deploy'` / `':after-deploy'` to
`boot-up.serve.steps` / `boot-up.deploy.steps`. Need a different position
entirely? The whole pipeline is published config — implement
`Igne\LaravelBootUp\Contracts\Step` and insert your own step class anywhere.

## In exported deployment scripts

`php artisan generate:deploy-script` embeds your project commands into the generated
Forge / Fortrabbit scripts (and the CI `test.sh`) in the four-phase order above,
with each description rendered as a `#` comment above its command. On a Forge
zero-downtime site, `afterDeploy()` commands run inside `$ACTIVATE_RELEASE()` —
after the symlink swap, once the new release is serving.
