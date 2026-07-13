# Project commands

Hook your project's own commands into the boot/deploy flow — code generators,
schema exporters, cache warmers — without touching the package.

## How it works

1. Create a class implementing
   `Igne\LaravelBootstrap\Deploy\ProvidesProjectCommands` (see
   [examples/ProjectCommands.php](examples/ProjectCommands.php)):

```php
namespace App\Bootstrap;

use Igne\LaravelBootstrap\Deploy\ProjectCommand;
use Igne\LaravelBootstrap\Deploy\ProvidesProjectCommands;

final class ProjectCommands implements ProvidesProjectCommands
{
    public function beforeMigrations(): array
    {
        return [
            ProjectCommand::artisan('wayfinder:generate --path=resources/js/wayfinder', 'Generating routes...'),
        ];
    }

    public function afterMigrations(): array
    {
        return [
            ProjectCommand::artisan('model:typer', 'Generating model types...'),
            ProjectCommand::packageManager('run zodgen', 'Generating Zod schemas...'),
        ];
    }
}
```

1. Bind it as a singleton in your `AppServiceProvider::register()`:

```php
$this->app->singleton(
    \Igne\LaravelBootstrap\Deploy\ProvidesProjectCommands::class,
    \App\Bootstrap\ProjectCommands::class,
);
```

That's it. `app:serve` and `app:deploy` resolve the binding lazily — no binding
means no project commands, no error.

## Command types

| Named constructor                                      | Runs as                                              |
| ------------------------------------------------------ | ---------------------------------------------------- |
| `ProjectCommand::artisan('scout:sync')`                | `php artisan scout:sync`                             |
| `ProjectCommand::composer('dump-autoload --optimize')` | `composer dump-autoload --optimize`                  |
| `ProjectCommand::packageManager('run build')`          | `bun run build` (or your configured package manager) |

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

```text
composer install          (deploy step)
frontend install          (deploy step)
→ beforeMigrations()
migrations
→ afterMigrations()
framework caches / finalize
queue worker, assets, ...
```

Need a different position? The whole pipeline is published config — implement
`Igne\LaravelBootstrap\Serve\Step` and insert your own step class anywhere in
`bootstrap.serve_steps` / `bootstrap.deploy_steps` instead.

## In exported deployment scripts

`php artisan app:deploy-script` embeds your project commands into the generated
Forge / Fortrabbit scripts at the same before/after-migrations positions, with
each description rendered as a `#` comment above its command.
