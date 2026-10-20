# Deployment scripts

`generate:deploy-script` turns this package's config (package manager,
migrations, finalize commands, queue usage, and your bound project commands)
into a deployment script for your hosting platform. A platform that reads its
settings from a committed **deploy file** (fortrabbit) gets that file written;
any other platform gets a paste-ready script printed:

```bash
php artisan generate:deploy-script forge production
php artisan generate:deploy-script forge production --classic
php artisan generate:deploy-script fortrabbit production               # writes .fortrabbit/deploy.yml
php artisan generate:deploy-script fortrabbit staging --in-terminal    # prints paste-ready dashboard commands
php artisan generate:deploy-script                                     # prompts for platform + environment
php artisan generate:deploy-script forge production --output=deploy.sh
```

An existing deploy file is only replaced after you confirm (or with `--force`).
`--output` always wins: it writes whatever would have been printed or written to
the path you give.

## Environments

The **environment** argument tunes the script:

- `development` keeps dev dependencies and skips `artisan optimize`.
- `staging` and `production` add `--no-dev` and framework caching.

## Forge

[Laravel Forge](https://forge.laravel.com) is Laravel's server-management
platform: it provisions your own servers and deploys via a per-site deployment
script.

- The default script is **zero-downtime**: it uses Forge's release macros
  (`$CREATE_RELEASE()`, `$ACTIVATE_RELEASE()`, `$RESTART_QUEUES()`) so each
  deploy builds a fresh release directory and switches over atomically. Paste it
  into your site's deployment script.
- The `--classic` variant (git pull + PHP-FPM reload) is for sites created
  **without** zero-downtime deployments — the two styles cannot be mixed. Use
  the one that matches how the site was created in Forge.

## fortrabbit

[fortrabbit](https://www.fortrabbit.com) is a Laravel-focused PaaS: you
`git push` to deploy, and each environment runs two command lists.

- **Build commands** — recreating the gitignored `storage/framework`
  directories, then dependency installation and the frontend build.
- **Post deploy commands** — recreating the `storage/framework` directories
  again, then migrations, optimization, finalize commands and the service
  restarts.

fortrabbit rebuilds the tree from git on every push, so `cache`, `sessions` and
`views` are missing on a fresh release. Both lists start with an idempotent
`mkdir -p` so the build (Composer's `package:discover` boots the app) and the
live release each find them in place.

### The deploy file (default)

`generate:deploy-script fortrabbit {environment}` writes
[`.fortrabbit/deploy.yml`](https://docs.fortrabbit.com/platform/deployment/deploy-file).
Commit it to the branch the environment deploys.

Once that file is on the deployed branch it replaces **all** of the dashboard's
deployment settings, and a key it leaves out falls back to fortrabbit's default,
not to the dashboard value. So the file restates what fortrabbit's Laravel
template configures there:

| Setting                        | Value                                   | Why                                                       |
| ------------------------------ | --------------------------------------- | --------------------------------------------------------- |
| `build.cachedDirectories`      | `vendor`, `node_modules`, `.pnpm-store` | Dependencies carry over between deployments.              |
| `distribution.strategy`        | `replace`                               | fortrabbit's Laravel default.                             |
| `distribution.excludePatterns` | `/storage`, `/node_modules`             | Without `/storage`, a deploy would wipe persistent files. |

If you have customised any of these in the dashboard, carry the change over into
the file. `staging` and `production` render identically, so one committed file
serves both branches; `development` differs (dev dependencies, no `optimize`).

### Dashboard commands

`--in-terminal` prints the two lists instead, ready to paste into the dashboard
fields — for an environment you keep configured in the dashboard. It does not
touch the deploy file; a deploy file on the branch still overrides whatever is
pasted.

## Package managers in generated scripts

Generated scripts never install a package manager globally: fortrabbit's build
environment refuses `npm i -g`, and Forge servers ship only Node and npm.

| Manager | Generated invocation      | Version                                           |
| ------- | ------------------------- | ------------------------------------------------- |
| npm     | `npm`                     | The platform's Node                               |
| yarn    | `npx --yes corepack yarn` | The `packageManager` pin in package.json          |
| pnpm    | `npx --yes corepack pnpm` | The `packageManager` pin, else Corepack's default |
| bun     | `npx --yes bun`           | The latest release                                |

[Corepack](https://yarnpkg.com/corepack) runs the exact version your
`package.json` pins. It goes through `npx` because Node 25 and newer no longer
bundle it; where Node still does, `npx` uses that copy.

`yarn` means **Yarn 2 and newer** (the scripts install with `--immutable`). Pin
it so every machine and server runs the same release:

```bash
npx corepack use yarn@4   # writes "packageManager": "yarn@4.x.y" and installs — commit package.json
```

Without that pin Corepack falls back to Yarn 1, which cannot read a Yarn 2+
lockfile; the generated script carries a `#!` warning until the pin is there.

## Service restarts

A generated script tells the project's long-running services to pick up the new
code, matching whatever it actually runs:

| Service       | Command                                                                      | When                                                   |
| ------------- | ---------------------------------------------------------------------------- | ------------------------------------------------------ |
| Queue workers | `queue:restart` (or Forge's own `$RESTART_QUEUES()` on a zero-downtime site) | `queue.enabled`, and Horizon is not managing the queue |
| Horizon       | `horizon:terminate`                                                          | `laravel/horizon` is installed and `horizon.enabled`   |
| Reverb        | `reverb:restart`                                                             | `laravel/reverb` is installed and `reverb.enabled`     |

Horizon supervises its own workers and does not answer `queue:restart`, so a
Horizon project gets `horizon:terminate` _instead of_ the queue restart rather
than as well as it. The scheduler is absent on purpose: in production that is
cron invoking `schedule:run`, not a process a deploy can signal.

The queue connection is deliberately not consulted — it comes from the deploy
target's environment, not from the machine generating the script.

## Project commands

Your [`ProvidesDeployTasks` binding](CUSTOM_COMMANDS.md) is embedded across all
four phases (`beforeDeploy` → `beforeMigrations` → `afterMigrations` →
`afterDeploy`), with each description rendered as a comment above its command.
On a Forge zero-downtime site, `afterDeploy` commands run after
`$ACTIVATE_RELEASE()` — once the release is swapped in and serving; on classic
Forge and fortrabbit they run as the final post-deploy commands.

## Custom platforms

Deploying somewhere else (Envoyer, Ploi, plain SSH)? Register your own generator
— see [Extending the package](EXTENDING.md#custom-deployment-platforms). If the
platform reads a committed deploy file, the generator can say where, and it gets
the same write-by-default and `--in-terminal` behaviour as fortrabbit.
