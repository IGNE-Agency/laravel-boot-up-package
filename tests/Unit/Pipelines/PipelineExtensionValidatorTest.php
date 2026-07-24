<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\DeploymentPlan;
use Igne\LaravelBootUp\Data\PipelinePlan;
use Igne\LaravelBootUp\Enums\DeployHookHost;
use Igne\LaravelBootUp\Enums\DeploymentEnvironment;
use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Exceptions\PipelineException;
use Igne\LaravelBootUp\Pipelines\CiScripts;
use Igne\LaravelBootUp\Pipelines\GitHubActionsGenerator;
use Igne\LaravelBootUp\Pipelines\PipelineExtensionValidator;

function validatorPlan(bool $pint = true, DeployHookHost $host = DeployHookHost::FORTRABBIT): PipelinePlan
{
    return new PipelinePlan(
        deployment: new DeploymentPlan(
            environment: DeploymentEnvironment::DEVELOPMENT,
            migrate: true,
            finalize: [],
            beforeMigrations: [],
            afterMigrations: [],
            frontend: false,
            packageManager: PackageManager::NPM,
            restartQueues: false,
        ),
        nova: false,
        composerAuth: false,
        pint: $pint,
        phpVersion: '8.4',
        branchEnvironments: ['main' => 'production'],
        host: $host,
    );
}

function validate(array $steps = [], array $files = [], ?string $basePath = null, array $providers = ['github', 'bitbucket']): Igne\LaravelBootUp\Pipelines\PipelineExtensions
{
    return (new PipelineExtensionValidator($basePath ?? sys_get_temp_dir()))
        ->validate($steps, $files, new GitHubActionsGenerator(new CiScripts), validatorPlan(), $providers);
}

test('a valid step and inline file pass through to the extensions', function (): void {
    $extensions = validate(
        steps: [['id' => 'notify', 'job' => 'test', 'position' => 'after', 'run' => 'bash notify.sh']],
        files: [['path' => '.github/workflows/nightly.yml', 'contents' => "name: Nightly\n"]],
    );

    expect($extensions->steps)->toHaveCount(1)
        ->and($extensions->steps[0]->name)->toBe('notify') // defaults to the id
        ->and($extensions->files)->toHaveCount(1)
        ->and($extensions->files[0]->contents)->toBe("name: Nightly\n");
});

test('a step missing its id is rejected', function (): void {
    expect(fn () => validate(steps: [['job' => 'test', 'position' => 'after', 'run' => 'x']]))
        ->toThrow(PipelineException::class, 'missing a non-empty "id"');
});

test('duplicate step ids are rejected', function (): void {
    expect(fn () => validate(steps: [
        ['id' => 'dup', 'job' => 'test', 'position' => 'after', 'run' => 'a'],
        ['id' => 'dup', 'job' => 'build', 'position' => 'before', 'run' => 'b'],
    ]))->toThrow(PipelineException::class, 'Duplicate pipeline step id [dup]');
});

test('an invalid position is rejected', function (): void {
    expect(fn () => validate(steps: [['id' => 's', 'job' => 'test', 'position' => 'around', 'run' => 'x']]))
        ->toThrow(PipelineException::class, 'invalid position [around]');
});

test('a job that is not an anchor for the provider is rejected', function (): void {
    expect(fn () => validate(steps: [['id' => 's', 'job' => 'release', 'position' => 'after', 'run' => 'x']]))
        ->toThrow(PipelineException::class, 'unknown job [release]');
});

test('a step scoped to another provider is not anchor-checked for this run', function (): void {
    $extensions = validate(steps: [
        ['id' => 's', 'provider' => 'bitbucket', 'job' => 'anything', 'position' => 'after', 'run' => 'x'],
    ]);

    // Kept for its own provider's run; it simply does not render for GitHub.
    expect($extensions->stepsFor('github', 'anything', 'after'))->toBe([])
        ->and($extensions->steps)->toHaveCount(1);
});

test('an unknown provider is rejected', function (): void {
    expect(fn () => validate(steps: [['id' => 's', 'provider' => 'gitlab', 'job' => 'test', 'position' => 'after', 'run' => 'x']]))
        ->toThrow(PipelineException::class, 'unknown provider');
});

test('a file with both contents and a stub is rejected', function (): void {
    expect(fn () => validate(files: [['path' => 'a.yml', 'contents' => 'x', 'stub' => 'b.yml']]))
        ->toThrow(PipelineException::class, 'exactly one of "contents" or "stub"');
});

test('a file with neither contents nor a stub is rejected', function (): void {
    expect(fn () => validate(files: [['path' => 'a.yml']]))
        ->toThrow(PipelineException::class, 'exactly one of "contents" or "stub"');
});

test('an absolute or traversing file path is rejected', function (): void {
    expect(fn () => validate(files: [['path' => '/etc/passwd', 'contents' => 'x']]))
        ->toThrow(PipelineException::class, 'relative path');

    expect(fn () => validate(files: [['path' => '../escape.yml', 'contents' => 'x']]))
        ->toThrow(PipelineException::class, 'relative path');
});

test('a duplicate file path is rejected', function (): void {
    expect(fn () => validate(files: [
        ['path' => 'a.yml', 'contents' => 'x'],
        ['path' => 'a.yml', 'contents' => 'y'],
    ]))->toThrow(PipelineException::class, 'declared more than once');
});

test('a missing stub file is rejected, a present one is read verbatim', function (): void {
    $dir = sys_get_temp_dir().'/boot-up-stub-'.bin2hex(random_bytes(4));
    mkdir($dir.'/stubs', 0755, true);
    file_put_contents($dir.'/stubs/nightly.yml', "name: Nightly\non: schedule\n");

    expect(fn () => validate(files: [['path' => 'a.yml', 'stub' => 'stubs/missing.yml']], basePath: $dir))
        ->toThrow(PipelineException::class, 'stub that does not exist');

    $extensions = validate(files: [['path' => '.github/workflows/nightly.yml', 'stub' => 'stubs/nightly.yml']], basePath: $dir);

    expect($extensions->files[0]->contents)->toBe("name: Nightly\non: schedule\n");

    exec('rm -rf '.escapeshellarg($dir));
});
