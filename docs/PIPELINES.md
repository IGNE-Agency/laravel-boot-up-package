# CI/CD pipelines

`generate:pipeline` writes a thin provider pipeline, the shared shell scripts it
calls, and a `.env.pipeline` test environment — all at their canonical paths:

```bash
php artisan generate:pipeline github fortrabbit   # .github/workflows/ci.yml + scripts/ci/* + .env.pipeline
php artisan generate:pipeline bitbucket webhook   # bitbucket-pipelines.yml + scripts/ci/* + .env.pipeline
php artisan generate:pipeline github none         # checks only — no deploy steps, secrets or deploy-hook.sh
php artisan generate:pipeline                     # prompts for the provider and the deploy-hook host
php artisan generate:pipeline github fortrabbit --force   # overwrite existing files without asking
```

## The shared scripts

All logic lives in **`scripts/ci/*.sh`** — the YAML only wires them up, so both
providers run byte-identical sequences and every stage reproduces locally
(`bash scripts/ci/test.sh`):

- `bootstrap.sh` — composer install, `cp .env.pipeline .env`, Nova publish (only
  with `laravel/nova`; composer auth comes from a `COMPOSER_AUTH` secret), then
  a lockfile-strict Node install. The package manager is **detected from the
  committed lockfile at run time**, so switching from npm to pnpm never requires
  regenerating.
- `lint.sh` / `build.sh` / `test.sh` — three parallel status checks: Pint (only
  when installed), frontend build + an `artisan optimize` round-trip (mirrors
  what the deploy scripts run, so un-cacheable config or routes fail CI instead
  of the deploy), and the test suite with finalize/project commands around
  `migrate --force`.
- `deploy-hook.sh` — POSTs the environment's deploy webhook with retries, HTTPS
  enforcement and status checking. It always sends the `User-Agent: fortrabbit`
  header fortrabbit's webhook endpoint requires (without it, fortrabbit answers
  403); other hosts ignore it. Omitted when you pick the `none` host.

## Checks

Checks run on every pull request and on pushes to the deploy branches, against
in-memory SQLite. `.env.pipeline` carries the test configuration — commit it;
the generated `APP_KEY` is only ever used in CI.

The PHP version comes from your `composer.json` `require.php` (setup-php on
GitHub, the `laravelsail/php{XY}-composer` image on Bitbucket).

## Deploys

Deploys are **environment-scoped**: `boot-up.pipeline.branches` maps each branch
to a deployment environment. The defaults:

| Branch    | Environment   |
| --------- | ------------- |
| `develop` | `development` |
| `staging` | `staging`     |
| `main`    | `production`  |

Create each environment on your provider (GitHub → Settings → Environments;
Bitbucket → Repository settings → Deployments) and give it a `DEPLOY_HOOK`
secret holding your host's deploy trigger URL — the command output shows exactly
where to find it for the host you picked (fortrabbit, Laravel Forge, or a
generic webhook).

- An unset hook skips that deploy with a notice.
- A green push deploys only its own branch's environment; in-flight deploys are
  never cancelled.
- Want production approvals? Add required reviewers to the GitHub environment,
  or `trigger: manual` to the Bitbucket step.

No deploy-hook host yet? Pass `none` (or pick it at the prompt) to generate a
**checks-only pipeline**: lint, build and test still run on pull requests and on
pushes to the mapped branches, but the deploy steps, `DEPLOY_HOOK` secrets and
`deploy-hook.sh` are omitted. Rerun `generate:pipeline` with a host later to add the
deploys.

## Secrets & next steps

After generating, the command prints a table of the secrets to create, a
guidance section per secret (the exact settings path and where its value comes
from on your deploy-hook host), and a "Next steps" list (branch protection
checks, enabling Bitbucket Pipelines once).

## Extending the pipeline

Two config surfaces let you extend a generated pipeline without replacing the
generator. `generate:pipeline` regenerates its files from this config every run, so
reruns are **idempotent** — nothing is ever duplicated, and files it does not
manage are never touched.

### Inject steps

`boot-up.pipeline.steps` splices extra steps into a generated job. Each step
attaches to a **job anchor** — `lint` (only with Pint), `build`, `test`, or
`deploy` — `before` or `after` that job's own step:

```php
'pipeline' => [
    'steps' => [
        [
            'id' => 'notify-slack',   // unique — drives validation and idempotency
            'job' => 'test',          // lint | build | test | deploy
            'position' => 'after',    // before | after
            'name' => 'Notify Slack',
            'run' => 'bash scripts/ci/notify.sh',
            'env' => ['WEBHOOK' => '${{ secrets.SLACK_WEBHOOK }}'], // GitHub only
            'provider' => 'github',   // optional — omit to inject into every provider
        ],
    ],
],
```

On GitHub the step renders as a job step (with its optional `env` block). On
Bitbucket it renders as a `script` line (per-line `env` is a GitHub concept —
expose values as repository/deployment variables instead, which are already in
the environment).

### Add files

`boot-up.pipeline.files` emits extra whole files verbatim. Give each a relative
`path` and exactly one of `contents` (inline) or `stub` (a file read verbatim,
relative to the project root):

```php
'pipeline' => [
    'files' => [
        [
            'path' => '.github/workflows/nightly.yml',
            'stub' => 'stubs/nightly.yml',
            'provider' => 'github',   // optional
            'executable' => false,    // optional — chmod 0755 when true
        ],
    ],
],
```

### Validation

Configuration is validated before anything is written, so a mistake fails the
run cleanly (and leaves your files untouched) rather than emitting broken YAML:
unknown job anchors, duplicate step ids, an invalid `position`, an unknown
`provider`, a missing `run`, absolute or `..` paths, a file with both/neither
`contents` and `stub`, and missing stub files all produce an actionable error.

## Custom git providers

Using GitLab or something else? Register your own generator — see
[Extending the package](EXTENDING.md#custom-git-providers).
