# Deployment scripts

`generate:deploy-script` turns this package's config (package manager, migrations,
finalize commands, queue usage, and your bound project commands) into a
paste-ready deployment script for your hosting platform:

```bash
php artisan generate:deploy-script forge production
php artisan generate:deploy-script forge production --classic
php artisan generate:deploy-script fortrabbit staging
php artisan generate:deploy-script                         # prompts for platform + environment
php artisan generate:deploy-script forge production --output=deploy.sh
```

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
`git push` to deploy and configure two command lists per environment in the
dashboard instead of one script.

- **Build commands** — dependency installation and the frontend build (your
  configured package manager is installed via `npm i -g` when it isn't npm).
- **Post deploy commands** — migrations, optimization, finalize commands and a
  queue restart.

`generate:deploy-script fortrabbit {environment}` outputs both lists, ready to paste
into the matching dashboard fields.

## Project commands

Your [`ProvidesProjectCommands` binding](CUSTOM_COMMANDS.md) is embedded across
all four phases (`beforeDeploy` → `beforeMigrations` → `afterMigrations` →
`afterDeploy`), with each description rendered as a comment above its command.
On a Forge zero-downtime site, `afterDeploy` commands run inside
`$ACTIVATE_RELEASE()` — after the release is swapped in and serving; on classic
Forge and fortrabbit they run as the final post-deploy commands.

## Custom platforms

Deploying somewhere else (Envoyer, Ploi, plain SSH)? Register your own generator
— see [Extending the package](EXTENDING.md#custom-deployment-platforms).
