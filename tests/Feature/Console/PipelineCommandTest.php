<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\StaticPipelineGenerator;
use Illuminate\Support\Facades\File;

afterEach(function (): void {
    File::deleteDirectory(base_path('.github'));
    File::deleteDirectory(base_path('scripts'));
    @unlink(base_path('bitbucket-pipelines.yml'));
    @unlink(base_path('.env.pipeline'));
    @unlink(base_path('static-pipeline.yml'));
});

test('github writes the workflow, the ci scripts and .env.pipeline at their canonical paths', function (): void {
    $this->artisan('app:pipeline', ['provider' => 'github'])->assertSuccessful();

    $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
    $deployHook = (string) file_get_contents(base_path('scripts/ci/deploy-hook.sh'));
    $env = (string) file_get_contents(base_path('.env.pipeline'));

    expect($workflow)->toContain('# CI/CD pipeline (GitHub Actions)')
        ->and($workflow)->toContain('uses: shivammathur/setup-php@v2')
        ->and($workflow)->toContain('run: bash scripts/ci/deploy-hook.sh production "${DEPLOY_HOOK:-}"')
        ->and($workflow)->toContain('environment: production')
        ->and($deployHook)->toContain("--header 'User-Agent: fortrabbit'")
        ->and($env)->toContain('APP_ENV=testing')
        ->and($env)->toContain('DB_CONNECTION=sqlite')
        ->and($env)->toContain('DB_DATABASE=:memory:')
        ->and($env)->toMatch('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/m');
});

test('the ci scripts are written executable', function (): void {
    $this->artisan('app:pipeline', ['provider' => 'github'])->assertSuccessful();

    foreach (['bootstrap.sh', 'build.sh', 'test.sh', 'deploy-hook.sh'] as $script) {
        expect(is_executable(base_path("scripts/ci/{$script}")))->toBeTrue("scripts/ci/{$script} is not executable");
    }
});

test('bitbucket writes bitbucket-pipelines.yml at the repo root plus the same scripts', function (): void {
    $this->artisan('app:pipeline', ['provider' => 'bitbucket'])->assertSuccessful();

    $pipeline = (string) file_get_contents(base_path('bitbucket-pipelines.yml'));

    expect($pipeline)->toContain('# CI/CD pipeline (Bitbucket Pipelines)')
        ->and($pipeline)->toContain('image: laravelsail/php')
        ->and($pipeline)->toContain('deployment: production')
        ->and(is_file(base_path('scripts/ci/deploy-hook.sh')))->toBeTrue()
        ->and(is_file(base_path('.env.pipeline')))->toBeTrue();
});

test('prompts for the provider when omitted', function (): void {
    $this->artisan('app:pipeline')
        ->expectsQuestion('Which git provider should the pipeline target?', 'bitbucket')
        ->assertSuccessful();

    expect(is_file(base_path('bitbucket-pipelines.yml')))->toBeTrue();
});

test('rejects an unknown provider', function (): void {
    $this->artisan('app:pipeline', ['provider' => 'gitlab'])->assertFailed();

    expect(is_file(base_path('.env.pipeline')))->toBeFalse();
});

test('a declined overwrite writes nothing at all', function (): void {
    File::ensureDirectoryExists(base_path('.github/workflows'));
    file_put_contents(base_path('.github/workflows/ci.yml'), 'hand-rolled workflow');

    $this->artisan('app:pipeline', ['provider' => 'github'])
        ->expectsConfirmation('.github/workflows/ci.yml already exists. Overwrite it?')
        ->assertSuccessful();

    expect((string) file_get_contents(base_path('.github/workflows/ci.yml')))->toBe('hand-rolled workflow')
        ->and(is_file(base_path('.env.pipeline')))->toBeFalse()
        ->and(is_dir(base_path('scripts')))->toBeFalse();
});

test('multiple existing files get one summary confirmation', function (): void {
    File::ensureDirectoryExists(base_path('.github/workflows'));
    file_put_contents(base_path('.github/workflows/ci.yml'), 'hand-rolled workflow');
    file_put_contents(base_path('.env.pipeline'), 'APP_ENV=stale');

    $this->artisan('app:pipeline', ['provider' => 'github'])
        ->expectsConfirmation('Overwrite these 2 existing files? .github/workflows/ci.yml, .env.pipeline')
        ->assertSuccessful();

    expect((string) file_get_contents(base_path('.env.pipeline')))->toBe('APP_ENV=stale')
        ->and(is_dir(base_path('scripts')))->toBeFalse();
});

test('--force overwrites existing files without asking', function (): void {
    File::ensureDirectoryExists(base_path('.github/workflows'));
    file_put_contents(base_path('.github/workflows/ci.yml'), 'hand-rolled workflow');
    file_put_contents(base_path('.env.pipeline'), 'APP_ENV=stale');

    $this->artisan('app:pipeline', ['provider' => 'github', '--force' => true])->assertSuccessful();

    expect((string) file_get_contents(base_path('.github/workflows/ci.yml')))->toContain('# CI/CD pipeline (GitHub Actions)')
        ->and((string) file_get_contents(base_path('.env.pipeline')))->toContain('APP_ENV=testing');
});

test('a nova project gets composer auth in the workflow, nova publish in bootstrap.sh and its php version', function (): void {
    $fixture = sys_get_temp_dir().'/boot-up-nova-composer-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($fixture, json_encode([
        'require' => ['php' => '^8.3', 'laravel/nova' => '^5.0'],
    ]));

    app()->singleton(ComposerJson::class, fn () => new ComposerJson($fixture));

    $this->artisan('app:pipeline', ['provider' => 'github'])->assertSuccessful();

    $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
    $bootstrap = (string) file_get_contents(base_path('scripts/ci/bootstrap.sh'));

    expect($workflow)->toContain('COMPOSER_AUTH: ${{ secrets.COMPOSER_AUTH }}')
        ->and($workflow)->toContain("php-version: '8.3'")
        ->and($bootstrap)->toContain('php artisan nova:publish');

    @unlink($fixture);
});

test('a project with pint gets a lint check on both providers', function (): void {
    $fixture = sys_get_temp_dir().'/boot-up-pint-composer-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($fixture, json_encode([
        'require-dev' => ['laravel/pint' => '^1.27'],
    ]));

    app()->singleton(ComposerJson::class, fn () => new ComposerJson($fixture));

    $this->artisan('app:pipeline', ['provider' => 'github', '--force' => true])->assertSuccessful();
    $this->artisan('app:pipeline', ['provider' => 'bitbucket', '--force' => true])->assertSuccessful();

    $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
    $pipeline = (string) file_get_contents(base_path('bitbucket-pipelines.yml'));
    $lint = (string) file_get_contents(base_path('scripts/ci/lint.sh'));

    expect($workflow)->toContain('run: bash scripts/ci/lint.sh')
        ->and($workflow)->toContain('needs: [lint, build, test]')
        ->and($pipeline)->toContain('- step: *lint')
        ->and($lint)->toContain('vendor/bin/pint --test');

    @unlink($fixture);
});

test('a custom generator registered in config is selectable and its files are honored', function (): void {
    config()->set('boot-up.pipeline.generators', ['static' => StaticPipelineGenerator::class]);

    $this->artisan('app:pipeline', ['provider' => 'static'])->assertSuccessful();

    expect((string) file_get_contents(base_path('static-pipeline.yml')))->toContain('static-pipeline for php');
});

test('remapped branches flow from config into the generated pipeline', function (): void {
    config()->set('boot-up.pipeline.branches', ['main' => 'production']);

    $this->artisan('app:pipeline', ['provider' => 'github'])->assertSuccessful();

    $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($workflow)->toContain('branches: [main]')
        ->and($workflow)->toContain('deploy-production:')
        ->and($workflow)->toContain("github.ref == 'refs/heads/main'")
        ->and($workflow)->not->toContain('deploy-development');
});
