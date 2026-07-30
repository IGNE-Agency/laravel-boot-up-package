<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Pipelines;

use Igne\LaravelBootUp\Data\DeployTask;
use Igne\LaravelBootUp\Data\GeneratedFile;
use Igne\LaravelBootUp\Data\Lines;
use Igne\LaravelBootUp\Data\PipelinePlan;
use Igne\LaravelBootUp\Enums\DeploymentEnvironment;
use Igne\LaravelBootUp\Enums\PackageManager;

/**
 * Renders the shared scripts/ci/*.sh files every provider pipeline calls,
 * so GitHub and Bitbucket execute the identical sequence and cannot drift
 * apart — and any script can be reproduced locally (`bash scripts/ci/test.sh`).
 *
 * Generation-time knowledge (Nova, Pint, migrate/finalize commands) is baked
 * in; the Node package manager is detected from the committed lockfile at run
 * time so switching lockfiles never requires regenerating.
 */
final class CiScripts
{
    public const string DIRECTORY = 'scripts/ci';

    /**
     * @return list<GeneratedFile>
     */
    public function files(PipelinePlan $plan): array
    {
        return collect([
            $this->bootstrap($plan),
            $this->lint($plan),
            $this->build($plan),
            $this->test($plan),
            $plan->host->deploys() ? $this->deployHook() : null,
        ])->filter()->values()->all();
    }

    public function bootstrap(PipelinePlan $plan): GeneratedFile
    {
        $script = $this->header('Install dependencies and prepare the CI environment (sourced by build.sh and test.sh).')
            ->blank()
            ->comment('Run from the repository root, wherever the script is invoked from.')
            ->line('cd "$(dirname "${BASH_SOURCE[0]}")/../.."')
            ->lineWithBreak('echo "==> Installing Composer dependencies"')
            ->line('composer install --no-interaction --prefer-dist --optimize-autoloader --no-progress')
            ->lineWithBreak('echo "==> Preparing the test environment"')
            ->line("cp {$plan->envFile} .env")
            ->line('mkdir -p storage/framework/cache storage/framework/sessions storage/framework/testing storage/framework/views')
            ->when($plan->nova, fn (Lines $script) => $script
                ->lineWithBreak('echo "==> Publishing Nova assets"')
                ->line('php artisan nova:publish'));

        $this->packageManagerSetup($script, $plan);

        return $this->script('bootstrap.sh', $script);
    }

    /**
     * A --no-scripts install is enough for Pint — no app boot required,
     * which is why lint does not source bootstrap.sh.
     */
    public function lint(PipelinePlan $plan): ?GeneratedFile
    {
        if (! $plan->pint) {
            return null;
        }

        $script = $this->header('Check the code style with Pint.')
            ->blank()
            ->comment('Run from the repository root, wherever the script is invoked from.')
            ->line('cd "$(dirname "${BASH_SOURCE[0]}")/../.."')
            ->lineWithBreak('echo "==> Installing Composer dependencies (no app scripts)"')
            ->line('composer install --no-interaction --prefer-dist --no-progress --no-scripts')
            ->lineWithBreak('echo "==> Checking code style"')
            ->line('vendor/bin/pint --test');

        return $this->script('lint.sh', $script);
    }

    public function build(PipelinePlan $plan): GeneratedFile
    {
        $script = $this->header('Build the frontend and validate the framework caches.')
            ->lineWithBreak('source "$(dirname "${BASH_SOURCE[0]}")/bootstrap.sh"');

        $this->frontendBuild($script, $plan);

        $optimizing = collect(DeploymentEnvironment::cases())
            ->filter(fn (DeploymentEnvironment $environment): bool => $environment->optimize())
            ->map(fn (DeploymentEnvironment $environment): string => $environment->value)
            ->implode('/');

        $script->blank()
            ->comment('`artisan optimize` mirrors what the generated deploy scripts run on')
            ->comment("{$optimizing}, so un-cacheable config, routes or views fail")
            ->comment('this job instead of the deploy.')
            ->line('echo "==> Validating framework caches"')
            ->line('php artisan optimize')
            ->line('php artisan optimize:clear');

        return $this->script('build.sh', $script);
    }

    public function test(PipelinePlan $plan): GeneratedFile
    {
        $script = $this->header('Run the test suite against the committed CI environment.')
            ->lineWithBreak('source "$(dirname "${BASH_SOURCE[0]}")/bootstrap.sh"');

        // Feature tests may render Blade views that need the Vite manifest.
        $this->frontendBuild($script, $plan);

        $script->lineWithBreak('echo "==> Running the test suite"')
            ->line('php artisan config:clear')
            ->each($plan->deployment->beforeDeploy, fn (Lines $script, DeployTask $command) => $script
                ->lines($this->projectCommand($command, $plan)))
            ->each($plan->deployment->finalize, fn (Lines $script, string $command) => $script
                ->line("php artisan {$command}"))
            ->each($plan->deployment->beforeMigrations, fn (Lines $script, DeployTask $command) => $script
                ->lines($this->projectCommand($command, $plan)))
            ->lineIf($plan->deployment->migrate, 'php artisan migrate --force')
            ->each($plan->deployment->afterMigrations, fn (Lines $script, DeployTask $command) => $script
                ->lines($this->projectCommand($command, $plan)))
            ->each($plan->deployment->afterDeploy, fn (Lines $script, DeployTask $command) => $script
                ->lines($this->projectCommand($command, $plan)))
            ->line('php artisan test');

        return $this->script('test.sh', $script);
    }

    /**
     * Plan-independent: `deploy-hook.sh <environment> <url>`. Curls the
     * deploy webhook, skipping gracefully when no URL is configured.
     */
    public function deployHook(): GeneratedFile
    {
        $script = $this->header(
            'Trigger a deploy webhook: deploy-hook.sh <environment> <url>',
            'Works with any HTTPS POST deploy hook (fortrabbit, Forge, Envoyer, Ploi, ...).',
            'The User-Agent header is required by fortrabbit — its webhook endpoint',
            'rejects curl\'s default agent with a 403 — and is harmless everywhere else.',
        )
            ->lineWithBreak('environment="${1:?Usage: deploy-hook.sh <environment> <url>}"')
            ->line('url="${2:-}"')
            ->lineWithBreak('if [ -z "$url" ]; then')
            ->indent(2, fn (Lines $script) => $script
                ->line('echo "No deploy hook configured for ${environment} — skipping deploy."')
                ->line('exit 0'))
            ->line('fi')
            ->lineWithBreak('case "$url" in')
            ->indent(2, fn (Lines $script) => $script
                ->line('https://*) ;;')
                ->line('*) echo "Refusing to call a non-HTTPS deploy hook for ${environment}." >&2; exit 1 ;;'))
            ->line('esac')
            ->lineWithBreak('echo "Triggering the ${environment} deploy hook..."')
            ->lineWithBreak('response="$(mktemp)"')
            ->line("trap 'rm -f \"\$response\"' EXIT")
            ->blank()
            ->comment('No --fail: curl must exit 0 on HTTP errors so the status is checked (and')
            ->comment('the body shown) here instead. --retry covers transport failures and')
            ->comment('transient HTTP errors (408/429/5xx); a hard rejection like a 403 is')
            ->comment('never retried, so a rejected call cannot double-deploy.')
            ->line('status="$(curl --silent --show-error \\')
            ->indent(2, fn (Lines $script) => $script
                ->line('--connect-timeout 10 --max-time 120 \\')
                ->line('--retry 3 --retry-all-errors \\')
                ->line('--request POST \\')
                ->line("--header 'User-Agent: fortrabbit' \\")
                ->line('--output "$response" --write-out \'%{http_code}\' \\')
                ->line('"$url")"'))
            ->lineWithBreak('echo "Deploy hook responded with HTTP ${status}:"')
            ->line('cat "$response"')
            ->line('echo')
            ->lineWithBreak('case "$status" in')
            ->indent(2, fn (Lines $script) => $script
                ->line('2*) echo "Deploy triggered for ${environment}." ;;')
                ->line('*) echo "Deploy hook for ${environment} failed (HTTP ${status})." >&2; exit 1 ;;'))
            ->line('esac');

        return $this->script('deploy-hook.sh', $script);
    }

    /**
     * The configured branches as prose for generated header comments — the
     * plan's branchEnvironments keys, e.g. "develop, staging and main" for
     * the default map.
     */
    public function branchList(PipelinePlan $plan): string
    {
        return collect($plan->branchEnvironments)->keys()->join(', ', ' and ');
    }

    private function header(string $purpose, string ...$notes): Lines
    {
        return ScriptHeader::for('php artisan generate:pipeline', $purpose, ...$notes);
    }

    private function script(string $name, Lines $contents): GeneratedFile
    {
        return new GeneratedFile(self::DIRECTORY."/{$name}", $contents->render(), executable: true);
    }

    private function frontendBuild(Lines $script, PipelinePlan $plan): void
    {
        if (! $plan->deployment->frontend) {
            return;
        }

        $script->lineWithBreak('echo "==> Building frontend assets with ${PM}"')
            ->line('"$PM" run build');
    }

    /**
     * Detect the Node package manager from the committed lockfile and run its
     * lockfile-strict install. The detection arms and install lines are
     * generated from the PackageManager enum so it stays the source of truth.
     */
    private function packageManagerSetup(Lines $script, PipelinePlan $plan): void
    {
        if (! $plan->deployment->frontend) {
            return;
        }

        $script->blank()
            ->comment('The package manager is detected from the committed lockfile at run')
            ->comment('time, so switching lockfiles never requires regenerating this script.')
            ->line('detect_package_manager() {')
            ->indent(2, function (Lines $script): void {
                $keyword = 'if';

                foreach (PackageManager::cases() as $manager) {
                    if ($manager === PackageManager::NPM) {
                        continue;
                    }

                    $condition = "[ -f {$manager->lockfile()} ]";

                    if ($manager === PackageManager::BUN) {
                        $condition .= ' || [ -f bun.lockb ]';
                    }

                    $script->line("{$keyword} {$condition}; then echo {$manager->value}");
                    $keyword = 'elif';
                }

                $script->line('else echo '.PackageManager::NPM->value)
                    ->line('fi');
            })
            ->line('}')
            ->lineWithBreak('PM="$(detect_package_manager)"')
            ->line('export PM')
            ->lineWithBreak('echo "==> Installing Node dependencies with ${PM}"')
            ->line('command -v "$PM" >/dev/null 2>&1 || npm i -g "$PM"')
            ->lineWithBreak('case "$PM" in')
            ->indent(2, fn (Lines $script) => $script->each(
                PackageManager::cases(),
                fn (Lines $script, PackageManager $manager) => $script
                    ->line("{$manager->value}) {$manager->ciInstallLine()} ;;"),
            ))
            ->line('esac');
    }

    private function projectCommand(DeployTask $command, PipelinePlan $plan): Lines
    {
        // With a frontend, package-manager commands use the runtime-detected
        // $PM; without one, no $PM exists — fall back to the configured manager.
        $packageManager = $plan->deployment->frontend ? '"$PM"' : $plan->deployment->packageManager->value;

        // Descriptions render as echo so they show up in the CI log output.
        $escaped = str_replace(['\\', '"'], ['\\\\', '\"'], (string) $command->description);

        return Lines::make()
            ->lineIf($command->description !== null, "echo \"{$escaped}\"")
            ->line($command->shellLine('php artisan', 'composer', $packageManager));
    }
}
