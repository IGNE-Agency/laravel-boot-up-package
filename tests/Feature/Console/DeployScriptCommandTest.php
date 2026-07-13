<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Deploy\ProjectCommand;
use Igne\LaravelBootstrap\Deploy\ProvidesProjectCommands;
use Igne\LaravelBootstrap\Tests\Feature\Console\Fixtures\StaticScriptGenerator;

test('exports a forge production script from the package config', function (): void {
    config()->set('bootstrap.frontend.package_manager', 'pnpm');

    $this->artisan('app:deploy-script', ['platform' => 'forge', 'environment' => 'production'])
        ->expectsOutputToContain('$CREATE_RELEASE()')
        ->expectsOutputToContain('$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader')
        ->expectsOutputToContain('pnpm install --frozen-lockfile || pnpm install')
        ->expectsOutputToContain('$RESTART_QUEUES()')
        ->assertSuccessful();
});

test('exports a fortrabbit script with both dashboard sections', function (): void {
    $this->artisan('app:deploy-script', ['platform' => 'fortrabbit', 'environment' => 'staging'])
        ->expectsOutputToContain('# ─── Build commands ────────────────────────────────────────────')
        ->expectsOutputToContain('# ─── Post deploy commands ──────────────────────────────────────')
        ->expectsOutputToContain('php artisan migrate --force')
        ->assertSuccessful();
});

test('embeds the project\'s bound commands into the exported script', function (): void {
    app()->singleton(ProvidesProjectCommands::class, fn () => new class implements ProvidesProjectCommands
    {
        public function beforeMigrations(): array
        {
            return [ProjectCommand::artisan('wayfinder:generate', 'Generating routes...')];
        }

        public function afterMigrations(): array
        {
            return [];
        }
    });

    $this->artisan('app:deploy-script', ['platform' => 'forge', 'environment' => 'production'])
        ->expectsOutputToContain('# Generating routes...')
        ->expectsOutputToContain('$FORGE_PHP artisan wayfinder:generate')
        ->assertSuccessful();
});

test('the classic flag renders the non-zero-downtime forge variant', function (): void {
    $this->artisan('app:deploy-script', ['platform' => 'forge', 'environment' => 'production', '--classic' => true])
        ->expectsOutputToContain('git pull origin $FORGE_SITE_BRANCH')
        ->expectsOutputToContain('sudo -S service $FORGE_PHP_FPM reload')
        ->assertSuccessful();
});

test('writes the script to a file with --output', function (): void {
    $file = sys_get_temp_dir().'/bootstrap-deploy-script-'.bin2hex(random_bytes(4)).'.sh';

    $this->artisan('app:deploy-script', [
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
    $this->artisan('app:deploy-script')
        ->expectsQuestion('Which platform should the deployment script target?', 'fortrabbit')
        ->expectsQuestion('Which environment is this script for?', 'staging')
        ->expectsOutputToContain('# Fortrabbit deployment commands (staging)')
        ->assertSuccessful();
});

test('rejects an unknown platform', function (): void {
    $this->artisan('app:deploy-script', ['platform' => 'heroku', 'environment' => 'production'])
        ->assertFailed();
});

test('a custom generator registered in config is selectable', function (): void {
    config()->set('bootstrap.deploy.script_generators', ['static' => StaticScriptGenerator::class]);

    $this->artisan('app:deploy-script', ['platform' => 'static', 'environment' => 'production'])
        ->expectsOutputToContain('static-script for production')
        ->assertSuccessful();
});
