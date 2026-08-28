<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

afterEach(function (): void {
    File::deleteDirectory(base_path('.githooks'));

    if (isset($GLOBALS['hooksComposerFixture'])) {
        @unlink($GLOBALS['hooksComposerFixture']);
        unset($GLOBALS['hooksComposerFixture']);
    }
});

/** Point ComposerJson at a throwaway composer.json with or without Pint. */
function bindHooksComposer(bool $pint): void
{
    $fixture = sys_get_temp_dir().'/boot-up-hooks-composer-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($fixture, json_encode($pint
        ? ['require-dev' => ['laravel/pint' => '^1.27']]
        : ['name' => 'acme/app']));

    app()->singleton(ComposerJson::class, fn () => new ComposerJson($fixture));

    $GLOBALS['hooksComposerFixture'] = $fixture;
}

test('installs an executable pre-commit hook and points git at .githooks when Pint is present', function (): void {
    Process::fake(['*' => Process::result(output: 'true')]);
    bindHooksComposer(pint: true);

    $this->artisan('generate:git-hooks')->assertSuccessful();

    $hook = base_path('.githooks/pre-commit');

    expect(is_file($hook))->toBeTrue()
        ->and(is_executable($hook))->toBeTrue()
        ->and((string) file_get_contents($hook))->toContain('vendor/bin/pint --test')
        ->and((string) file_get_contents($hook))->toContain('regenerate with `php artisan generate:git-hooks`');

    Process::assertRan(fn ($process): bool => implode(' ', $process->command) === 'git config core.hooksPath .githooks');
});

test('does nothing but explains when Pint is not installed', function (): void {
    Process::fake(['*' => Process::result(output: 'true')]);
    bindHooksComposer(pint: false);

    $this->artisan('generate:git-hooks')
        ->expectsOutputToContain('Install laravel/pint')
        ->assertSuccessful();

    expect(is_file(base_path('.githooks/pre-commit')))->toBeFalse();

    Process::assertNotRan(fn ($process): bool => implode(' ', $process->command) === 'git config core.hooksPath .githooks');
});

test('fails cleanly when not inside a git work tree', function (): void {
    // The git work-tree probe is the first thing that runs and bails on failure.
    Process::fake(['*' => Process::result(exitCode: 128, errorOutput: 'fatal: not a git repository')]);
    bindHooksComposer(pint: true);

    $this->artisan('generate:git-hooks')
        ->expectsOutputToContain('Not a git repository')
        ->assertFailed();

    expect(is_file(base_path('.githooks/pre-commit')))->toBeFalse();
});

test('--force overwrites an existing pre-commit hook without asking', function (): void {
    Process::fake(['*' => Process::result(output: 'true')]);
    bindHooksComposer(pint: true);

    File::ensureDirectoryExists(base_path('.githooks'));
    file_put_contents(base_path('.githooks/pre-commit'), '# old hook');

    $this->artisan('generate:git-hooks', ['--force' => true])->assertSuccessful();

    expect((string) file_get_contents(base_path('.githooks/pre-commit')))->toContain('vendor/bin/pint --test');
});

test('a declined overwrite writes nothing', function (): void {
    Process::fake(['*' => Process::result(output: 'true')]);
    bindHooksComposer(pint: true);

    File::ensureDirectoryExists(base_path('.githooks'));
    file_put_contents(base_path('.githooks/pre-commit'), '# old hook');

    $this->artisan('generate:git-hooks')
        ->expectsConfirmation('.githooks/pre-commit already exists. Overwrite it?')
        ->assertSuccessful();

    expect((string) file_get_contents(base_path('.githooks/pre-commit')))->toBe('# old hook');
});
