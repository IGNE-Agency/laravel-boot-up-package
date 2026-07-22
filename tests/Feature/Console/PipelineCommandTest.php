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

/** Pin the branch map so assertions never depend on the package default. */
function pinDefaultBranches(): void
{
    config()->set('boot-up.pipeline.branches', [
        'develop' => 'development',
        'staging' => 'staging',
        'main' => 'production',
    ]);
}

test('github writes the workflow, the ci scripts and .env.pipeline at their canonical paths', function (): void {
    pinDefaultBranches();

    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'fortrabbit'])->assertSuccessful();

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
    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'fortrabbit'])->assertSuccessful();

    foreach (['bootstrap.sh', 'build.sh', 'test.sh', 'deploy-hook.sh'] as $script) {
        expect(is_executable(base_path("scripts/ci/{$script}")))->toBeTrue("scripts/ci/{$script} is not executable");
    }
});

test('bitbucket writes bitbucket-pipelines.yml at the repo root plus the same scripts', function (): void {
    pinDefaultBranches();

    $this->artisan('app:pipeline', ['provider' => 'bitbucket', 'host' => 'fortrabbit'])->assertSuccessful();

    $pipeline = (string) file_get_contents(base_path('bitbucket-pipelines.yml'));

    expect($pipeline)->toContain('# CI/CD pipeline (Bitbucket Pipelines)')
        ->and($pipeline)->toContain('image: laravelsail/php')
        ->and($pipeline)->toContain('deployment: production')
        ->and(is_file(base_path('scripts/ci/deploy-hook.sh')))->toBeTrue()
        ->and(is_file(base_path('.env.pipeline')))->toBeTrue();
});

test('injects a configured extra step into the generated workflow', function (): void {
    config()->set('boot-up.pipeline.steps', [[
        'id' => 'notify',
        'job' => 'test',
        'position' => 'after',
        'name' => 'Notify Slack',
        'run' => 'bash scripts/ci/notify.sh',
    ]]);

    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'fortrabbit'])->assertSuccessful();

    expect((string) file_get_contents(base_path('.github/workflows/ci.yml')))
        ->toContain('- name: Notify Slack')
        ->toContain('run: bash scripts/ci/notify.sh');
});

test('writes a configured extra file verbatim', function (): void {
    config()->set('boot-up.pipeline.files', [[
        'path' => '.github/workflows/nightly.yml',
        'contents' => "name: Nightly\non: schedule\n",
    ]]);

    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'fortrabbit'])->assertSuccessful();

    expect((string) file_get_contents(base_path('.github/workflows/nightly.yml')))
        ->toBe("name: Nightly\non: schedule\n");
});

test('regenerating the pipeline with extensions is byte-for-byte idempotent', function (): void {
    config()->set('boot-up.pipeline.steps', [[
        'id' => 'notify',
        'job' => 'test',
        'position' => 'after',
        'run' => 'bash scripts/ci/notify.sh',
    ]]);

    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'fortrabbit'])->assertSuccessful();
    $first = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'fortrabbit', '--force' => true])->assertSuccessful();

    expect((string) file_get_contents(base_path('.github/workflows/ci.yml')))->toBe($first);
});

test('an invalid extra step fails cleanly and writes nothing', function (): void {
    config()->set('boot-up.pipeline.steps', [[
        'id' => 'broken',
        'job' => 'nonexistent',
        'position' => 'after',
        'run' => 'x',
    ]]);

    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'fortrabbit'])
        ->expectsOutputToContain('unknown job [nonexistent]')
        ->assertFailed();

    expect(is_file(base_path('.github/workflows/ci.yml')))->toBeFalse();
});

test('prompts for the provider and host when omitted', function (): void {
    $this->artisan('app:pipeline')
        ->expectsQuestion('Which git provider should the pipeline target?', 'bitbucket')
        ->expectsQuestion('Which host receives the deploy hook?', 'fortrabbit')
        ->assertSuccessful();

    expect(is_file(base_path('bitbucket-pipelines.yml')))->toBeTrue();
});

test('prompts for the host when only the provider is given', function (): void {
    $this->artisan('app:pipeline', ['provider' => 'github'])
        ->expectsQuestion('Which host receives the deploy hook?', 'webhook')
        ->assertSuccessful();

    expect(is_file(base_path('.github/workflows/ci.yml')))->toBeTrue();
});

test('the none host writes a checks-only github workflow without deploy files or secrets', function (): void {
    pinDefaultBranches();

    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'none'])
        ->doesntExpectOutputToContain('DEPLOY_HOOK')
        ->assertSuccessful();

    $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($workflow)->toContain('branches: [develop, staging, main]')
        ->and($workflow)->toContain('run: bash scripts/ci/test.sh')
        ->and($workflow)->not->toContain('deploy-hook.sh')
        ->and($workflow)->not->toContain('environment:')
        ->and(is_file(base_path('scripts/ci/test.sh')))->toBeTrue()
        ->and(is_file(base_path('scripts/ci/deploy-hook.sh')))->toBeFalse()
        ->and(is_file(base_path('.env.pipeline')))->toBeTrue();
});

test('the none host writes a checks-only bitbucket pipeline', function (): void {
    $this->artisan('app:pipeline', ['provider' => 'bitbucket', 'host' => 'none'])->assertSuccessful();

    $pipeline = (string) file_get_contents(base_path('bitbucket-pipelines.yml'));

    expect($pipeline)->toContain('- step: *test')
        ->and($pipeline)->not->toContain('deployment:')
        ->and($pipeline)->not->toContain('deploy-hook.sh')
        ->and(is_file(base_path('scripts/ci/deploy-hook.sh')))->toBeFalse();
});

test('none is selectable at the host prompt', function (): void {
    $this->artisan('app:pipeline', ['provider' => 'github'])
        ->expectsQuestion('Which host receives the deploy hook?', 'none')
        ->assertSuccessful();

    expect(is_file(base_path('.github/workflows/ci.yml')))->toBeTrue()
        ->and(is_file(base_path('scripts/ci/deploy-hook.sh')))->toBeFalse();
});

test('rejects an unknown host', function (): void {
    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'heroku'])->assertFailed();

    expect(is_file(base_path('.env.pipeline')))->toBeFalse();
});

test('rejects an unknown provider', function (): void {
    $this->artisan('app:pipeline', ['provider' => 'gitlab'])->assertFailed();

    expect(is_file(base_path('.env.pipeline')))->toBeFalse();
});

test('a declined overwrite writes nothing at all', function (): void {
    File::ensureDirectoryExists(base_path('.github/workflows'));
    file_put_contents(base_path('.github/workflows/ci.yml'), 'hand-rolled workflow');

    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'fortrabbit'])
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

    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'fortrabbit'])
        ->expectsConfirmation('Overwrite these 2 existing files? .github/workflows/ci.yml, .env.pipeline')
        ->assertSuccessful();

    expect((string) file_get_contents(base_path('.env.pipeline')))->toBe('APP_ENV=stale')
        ->and(is_dir(base_path('scripts')))->toBeFalse();
});

test('--force overwrites existing files without asking', function (): void {
    File::ensureDirectoryExists(base_path('.github/workflows'));
    file_put_contents(base_path('.github/workflows/ci.yml'), 'hand-rolled workflow');
    file_put_contents(base_path('.env.pipeline'), 'APP_ENV=stale');

    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'fortrabbit', '--force' => true])->assertSuccessful();

    expect((string) file_get_contents(base_path('.github/workflows/ci.yml')))->toContain('# CI/CD pipeline (GitHub Actions)')
        ->and((string) file_get_contents(base_path('.env.pipeline')))->toContain('APP_ENV=testing');
});

test('a nova project gets composer auth in the workflow, nova publish in bootstrap.sh and its php version', function (): void {
    $fixture = sys_get_temp_dir().'/boot-up-nova-composer-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($fixture, json_encode([
        'require' => ['php' => '^8.3', 'laravel/nova' => '^5.0'],
    ]));

    app()->singleton(ComposerJson::class, fn () => new ComposerJson($fixture));

    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'fortrabbit'])->assertSuccessful();

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

    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'fortrabbit', '--force' => true])->assertSuccessful();
    $this->artisan('app:pipeline', ['provider' => 'bitbucket', 'host' => 'fortrabbit', '--force' => true])->assertSuccessful();

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

    $this->artisan('app:pipeline', ['provider' => 'static', 'host' => 'webhook'])->assertSuccessful();

    expect((string) file_get_contents(base_path('static-pipeline.yml')))->toContain('static-pipeline for php');
});

test('remapped branches flow from config into the generated pipeline', function (): void {
    config()->set('boot-up.pipeline.branches', ['main' => 'production']);

    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'fortrabbit'])->assertSuccessful();

    $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($workflow)->toContain('branches: [main]')
        ->and($workflow)->toContain('deploy-production:')
        ->and($workflow)->toContain("github.ref == 'refs/heads/main'")
        ->and($workflow)->not->toContain('deploy-development');
});

test('remapped branches drive the github approval instruction, not hardcoded names', function (): void {
    config()->set('boot-up.pipeline.branches', ['master' => 'acceptance']);

    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'webhook'])
        ->expectsOutputToContain('add required reviewers to the acceptance environment')
        ->doesntExpectOutputToContain('production')
        ->doesntExpectOutputToContain('(e.g. main)')
        ->assertSuccessful();
});

test('remapped branches drive the bitbucket approval instruction, not hardcoded names', function (): void {
    config()->set('boot-up.pipeline.branches', ['master' => 'acceptance']);

    $this->artisan('app:pipeline', ['provider' => 'bitbucket', 'host' => 'webhook'])
        ->expectsOutputToContain('add `trigger: manual` to the acceptance deploy step')
        ->doesntExpectOutputToContain('production')
        ->assertSuccessful();
});

// Each expectsOutputToContain can only match a distinct output chunk (one
// table, one note per section), so the assertions below sample one line from
// each section rather than several lines of the same one.
test('prints the slim secrets table with a guidance section per secret and the next steps', function (): void {
    pinDefaultBranches();

    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'fortrabbit'])
        ->expectsOutputToContain('deploys on push to develop')
        ->expectsOutputToContain('DEPLOY_HOOK — development environment')
        ->expectsOutputToContain('Add under, in your GitHub repository: Settings → Environments → staging → Environment secrets (create the environment first).')
        ->expectsOutputToContain('Example: https://api.fortrabbit.com/webhooks/environments/{app-env-id}/deploy/{secret}')
        ->expectsOutputToContain('1. Commit .github/workflows/ci.yml')
        ->expectsOutputToContain('Pipeline generated.')
        ->assertSuccessful();
});

test('the forge host shows forge guidance and never mentions fortrabbit', function (): void {
    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'forge'])
        ->expectsOutputToContain('Deployment trigger URL')
        ->doesntExpectOutputToContain('fortrabbit')
        ->assertSuccessful();
});

test('the webhook host shows neutral guidance and names no host', function (): void {
    $this->artisan('app:pipeline', ['provider' => 'github', 'host' => 'webhook'])
        ->expectsOutputToContain("Value: your host's HTTPS deploy hook URL for development")
        ->doesntExpectOutputToContain('fortrabbit')
        ->doesntExpectOutputToContain('Forge')
        ->assertSuccessful();
});
