<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Contracts\ProvidesDeployTasks;
use Igne\LaravelBootUp\Data\DeployTask;
use Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\DeployFileScriptGenerator;
use Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\StaticScriptGenerator;
use Illuminate\Support\Facades\File;

afterEach(function (): void {
    File::deleteDirectory(base_path('.fortrabbit'));
    File::deleteDirectory(base_path(dirname(DeployFileScriptGenerator::PATH)));
});

test('exports a forge production script from the package config', function (): void {
    config()->set('boot-up.frontend.package_manager', 'pnpm');

    $this->artisan('generate:deploy-script', ['platform' => 'forge', 'environment' => 'production'])
        ->expectsOutputToContain('$CREATE_RELEASE()')
        ->expectsOutputToContain('$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader')
        ->expectsOutputToContain('npx --yes corepack pnpm install --frozen-lockfile || npx --yes corepack pnpm install')
        ->expectsOutputToContain('$RESTART_QUEUES()')
        ->assertSuccessful();
});

test('writes the fortrabbit deploy file by default', function (): void {
    $this->artisan('generate:deploy-script', ['platform' => 'fortrabbit', 'environment' => 'staging'])
        ->expectsOutputToContain('Deploy file written to .fortrabbit/deploy.yml')
        ->assertSuccessful();

    expect((string) file_get_contents(base_path('.fortrabbit/deploy.yml')))
        ->toContain('# Fortrabbit deploy file (staging)')
        ->toContain('apiVersion: 1.0')
        ->toContain('    - php artisan migrate --force')
        // The file is meant to be committed: no catch-all .gitignore beside it.
        ->and(is_file(base_path('.fortrabbit/.gitignore')))->toBeFalse();
});

test('the deploy file carries the project\'s fortrabbit settings', function (): void {
    config()->set('boot-up.deploy.fortrabbit.cached_directories', ['vendor']);
    config()->set('boot-up.deploy.fortrabbit.exclude_patterns', ['/storage', '/public/uploads']);

    $this->artisan('generate:deploy-script', ['platform' => 'fortrabbit', 'environment' => 'production'])
        ->assertSuccessful();

    expect((string) file_get_contents(base_path('.fortrabbit/deploy.yml')))
        ->toContain("  cachedDirectories:\n    - vendor\ndistribution:")
        ->toContain("  excludePatterns:\n    - /storage\n    - /public/uploads\n");
});

test('prints the fortrabbit dashboard sections with --in-terminal', function (): void {
    $this->artisan('generate:deploy-script', ['platform' => 'fortrabbit', 'environment' => 'staging', '--in-terminal' => true])
        ->expectsOutputToContain('# ─── Build commands ────────────────────────────────────────────')
        ->expectsOutputToContain('# ─── Post deploy commands ──────────────────────────────────────')
        ->expectsOutputToContain('php artisan migrate --force')
        ->assertSuccessful();

    expect(is_dir(base_path('.fortrabbit')))->toBeFalse();
});

test('an existing deploy file is kept unless the overwrite is confirmed', function (): void {
    File::ensureDirectoryExists(base_path('.fortrabbit'));
    file_put_contents(base_path('.fortrabbit/deploy.yml'), "apiVersion: 1.0\n# hand-tuned\n");

    $this->artisan('generate:deploy-script', ['platform' => 'fortrabbit', 'environment' => 'production'])
        ->expectsConfirmation('.fortrabbit/deploy.yml already exists. Overwrite it?', 'no')
        ->assertSuccessful();

    expect((string) file_get_contents(base_path('.fortrabbit/deploy.yml')))->toBe("apiVersion: 1.0\n# hand-tuned\n");
});

test('--force replaces an existing deploy file without asking', function (): void {
    File::ensureDirectoryExists(base_path('.fortrabbit'));
    file_put_contents(base_path('.fortrabbit/deploy.yml'), "apiVersion: 1.0\n# hand-tuned\n");

    $this->artisan('generate:deploy-script', ['platform' => 'fortrabbit', 'environment' => 'production', '--force' => true])
        ->assertSuccessful();

    expect((string) file_get_contents(base_path('.fortrabbit/deploy.yml')))->toContain('# Fortrabbit deploy file (production)');
});

test('--output takes precedence over the deploy file', function (): void {
    $file = sys_get_temp_dir().'/boot-up-deploy-file-'.bin2hex(random_bytes(4)).'.yml';

    $this->artisan('generate:deploy-script', ['platform' => 'fortrabbit', 'environment' => 'production', '--output' => $file])
        ->assertSuccessful();

    expect((string) file_get_contents($file))->toContain('apiVersion: 1.0')
        ->and(is_dir(base_path('.fortrabbit')))->toBeFalse();

    @unlink($file);
});

test('embeds the project\'s bound commands into the exported script', function (): void {
    app()->singleton(ProvidesDeployTasks::class, fn () => new class implements ProvidesDeployTasks
    {
        public function beforeDeploy(): array
        {
            return [];
        }

        public function beforeMigrations(): array
        {
            return [DeployTask::artisan('wayfinder:generate', 'Generating routes...')];
        }

        public function afterMigrations(): array
        {
            return [];
        }

        public function afterDeploy(): array
        {
            return [];
        }
    });

    $this->artisan('generate:deploy-script', ['platform' => 'forge', 'environment' => 'production'])
        ->expectsOutputToContain('# Generating routes...')
        ->expectsOutputToContain('$FORGE_PHP artisan wayfinder:generate')
        ->assertSuccessful();
});

test('renders bound commands for all four phases in the forge script', function (): void {
    app()->singleton(ProvidesDeployTasks::class, fn () => new class implements ProvidesDeployTasks
    {
        public function beforeDeploy(): array
        {
            return [DeployTask::artisan('pennant:purge')];
        }

        public function beforeMigrations(): array
        {
            return [DeployTask::artisan('wayfinder:generate')];
        }

        public function afterMigrations(): array
        {
            return [DeployTask::artisan('model:typer')];
        }

        public function afterDeploy(): array
        {
            return [DeployTask::artisan('cache:warm')];
        }
    });

    // before-deploy runs before optimize; after-deploy runs after $ACTIVATE_RELEASE.
    $this->artisan('generate:deploy-script', ['platform' => 'forge', 'environment' => 'production'])
        ->expectsOutputToContain('$FORGE_PHP artisan pennant:purge')
        ->expectsOutputToContain('$FORGE_PHP artisan wayfinder:generate')
        ->expectsOutputToContain('$FORGE_PHP artisan model:typer')
        ->expectsOutputToContain('$FORGE_PHP artisan cache:warm')
        ->assertSuccessful();
});

test('the classic flag renders the non-zero-downtime forge variant', function (): void {
    $this->artisan('generate:deploy-script', ['platform' => 'forge', 'environment' => 'production', '--classic' => true])
        ->expectsOutputToContain('git pull origin $FORGE_SITE_BRANCH')
        ->expectsOutputToContain('sudo -S service $FORGE_PHP_FPM reload')
        ->assertSuccessful();
});

test('writes the script to a file with --output', function (): void {
    $file = sys_get_temp_dir().'/boot-up-deploy-script-'.bin2hex(random_bytes(4)).'.sh';

    $this->artisan('generate:deploy-script', [
        'platform' => 'forge',
        'environment' => 'development',
        '--output' => $file,
    ])->assertSuccessful();

    expect(is_file($file))->toBeTrue()
        ->and((string) file_get_contents($file))->toContain('$CREATE_RELEASE()')
        ->and((string) file_get_contents($file))->not->toContain('--no-dev');

    @unlink($file);
});

test('prompts for platform and environment when omitted', function (): void {
    $this->artisan('generate:deploy-script')
        ->expectsQuestion('Which platform should the deployment script target?', 'fortrabbit')
        ->expectsQuestion('Which environment is this script for?', 'staging')
        ->assertSuccessful();

    expect((string) file_get_contents(base_path('.fortrabbit/deploy.yml')))->toContain('# Fortrabbit deploy file (staging)');
});

test('rejects an unknown platform', function (): void {
    $this->artisan('generate:deploy-script', ['platform' => 'heroku', 'environment' => 'production'])
        ->assertFailed();
});

test('a custom generator registered in config is selectable', function (): void {
    config()->set('boot-up.deploy.script_generators', ['static' => StaticScriptGenerator::class]);

    $this->artisan('generate:deploy-script', ['platform' => 'static', 'environment' => 'production'])
        ->expectsOutputToContain('static-script for production')
        ->assertSuccessful();
});

test('any generator with a deploy file gets it written, or printed with --in-terminal', function (): void {
    config()->set('boot-up.deploy.script_generators', ['static-file' => DeployFileScriptGenerator::class]);

    $this->artisan('generate:deploy-script', ['platform' => 'static-file', 'environment' => 'production'])
        ->expectsOutputToContain('Deploy file written to '.DeployFileScriptGenerator::PATH)
        ->assertSuccessful();

    expect((string) file_get_contents(base_path(DeployFileScriptGenerator::PATH)))->toBe("static-deploy-file for production\n");

    $this->artisan('generate:deploy-script', ['platform' => 'static-file', 'environment' => 'production', '--in-terminal' => true])
        ->expectsOutputToContain('static-terminal for production')
        ->assertSuccessful();
});

test('a platform without a deploy file always prints', function (): void {
    $this->artisan('generate:deploy-script', ['platform' => 'forge', 'environment' => 'production'])
        ->expectsOutputToContain('$CREATE_RELEASE()')
        ->doesntExpectOutputToContain('Deploy file written')
        ->assertSuccessful();
});
