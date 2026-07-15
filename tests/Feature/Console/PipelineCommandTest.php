<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Pipelines\ComposerJson;
use Igne\LaravelBootstrap\Tests\Feature\Console\Fixtures\StaticPipelineGenerator;
use Illuminate\Support\Facades\File;

afterEach(function (): void {
    File::deleteDirectory(base_path('.github'));
    @unlink(base_path('bitbucket-pipelines.yml'));
    @unlink(base_path('.env.pipeline'));
    @unlink(base_path('static-pipeline.yml'));
});

test('github writes the workflow and .env.pipeline at their canonical paths', function (): void {
    $this->artisan('app:pipeline', ['provider' => 'github'])->assertSuccessful();

    $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
    $env = (string) file_get_contents(base_path('.env.pipeline'));

    expect($workflow)->toContain('# CI/CD pipeline (GitHub Actions)')
        ->and($workflow)->toContain('uses: shivammathur/setup-php@v2')
        ->and($workflow)->toContain('curl --silent --show-error --fail-with-body -X POST "$hook"')
        ->and($env)->toContain('APP_ENV=testing')
        ->and($env)->toContain('DB_CONNECTION=sqlite')
        ->and($env)->toContain('DB_DATABASE=:memory:')
        ->and($env)->toMatch('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/m');
});

test('bitbucket writes bitbucket-pipelines.yml at the repo root', function (): void {
    $this->artisan('app:pipeline', ['provider' => 'bitbucket'])->assertSuccessful();

    $pipeline = (string) file_get_contents(base_path('bitbucket-pipelines.yml'));

    expect($pipeline)->toContain('# CI/CD pipeline (Bitbucket Pipelines)')
        ->and($pipeline)->toContain('image: laravelsail/php')
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

test('a declined overwrite leaves the existing file untouched', function (): void {
    File::ensureDirectoryExists(base_path('.github/workflows'));
    file_put_contents(base_path('.github/workflows/ci.yml'), 'hand-rolled workflow');

    $this->artisan('app:pipeline', ['provider' => 'github'])
        ->expectsConfirmation('.github/workflows/ci.yml already exists. Overwrite it?')
        ->assertSuccessful();

    expect((string) file_get_contents(base_path('.github/workflows/ci.yml')))->toBe('hand-rolled workflow')
        ->and(is_file(base_path('.env.pipeline')))->toBeTrue();
});

test('--force overwrites existing files without asking', function (): void {
    File::ensureDirectoryExists(base_path('.github/workflows'));
    file_put_contents(base_path('.github/workflows/ci.yml'), 'hand-rolled workflow');
    file_put_contents(base_path('.env.pipeline'), 'APP_ENV=stale');

    $this->artisan('app:pipeline', ['provider' => 'github', '--force' => true])->assertSuccessful();

    expect((string) file_get_contents(base_path('.github/workflows/ci.yml')))->toContain('# CI/CD pipeline (GitHub Actions)')
        ->and((string) file_get_contents(base_path('.env.pipeline')))->toContain('APP_ENV=testing');
});

test('a nova project gets composer auth, nova publish and its composer.json php version', function (): void {
    $fixture = sys_get_temp_dir().'/bootstrap-nova-composer-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($fixture, json_encode([
        'require' => ['php' => '^8.3', 'laravel/nova' => '^5.0'],
    ]));

    app()->singleton(ComposerJson::class, fn () => new ComposerJson($fixture));

    $this->artisan('app:pipeline', ['provider' => 'github'])->assertSuccessful();

    $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($workflow)->toContain('COMPOSER_AUTH: ${{ secrets.COMPOSER_AUTH }}')
        ->and($workflow)->toContain('php artisan nova:publish')
        ->and($workflow)->toContain("php-version: '8.3'");

    @unlink($fixture);
});

test('a custom generator registered in config is selectable and its path is honored', function (): void {
    config()->set('bootstrap.pipeline.generators', ['static' => StaticPipelineGenerator::class]);

    $this->artisan('app:pipeline', ['provider' => 'static'])->assertSuccessful();

    expect((string) file_get_contents(base_path('static-pipeline.yml')))->toContain('static-pipeline for php');
});

test('remapped branches flow from config into the generated pipeline', function (): void {
    config()->set('bootstrap.pipeline.branches', ['main' => 'PROD_DEPLOY']);

    $this->artisan('app:pipeline', ['provider' => 'github'])->assertSuccessful();

    $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($workflow)->toContain('branches: [main]')
        ->and($workflow)->not->toContain('DEV_DEPLOY');
});
