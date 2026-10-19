<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Pipelines\GitHooks;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * The generated pre-commit hook, run for real: a throwaway repository, git
 * doing the staging and the package's own Pint behind vendor/bin/pint.
 */

beforeEach(function (): void {
    $this->repository = gitRepository();

    $hook = (new GitHooks)->preCommit(true);
    File::ensureDirectoryExists($this->repository.'/'.GitHooks::DIRECTORY);
    file_put_contents($this->repository.'/'.$hook->path, $hook->contents);

    File::ensureDirectoryExists($this->repository.'/vendor/bin');
    file_put_contents($this->repository.'/vendor/bin/pint', sprintf(
        "#!/usr/bin/env bash\nexec %s %s \"\$@\"\n",
        escapeshellarg(PHP_BINARY),
        escapeshellarg(dirname(__DIR__, 3).'/vendor/laravel/pint/builds/pint'),
    ));
    chmod($this->repository.'/vendor/bin/pint', 0755);
});

afterEach(function (): void {
    File::deleteDirectory($this->repository);
});

/**
 * A class that passes Pint's Laravel preset, or fails it on one rule
 * (`! $value`) that PSR-12 does not care about.
 */
function hookExampleClass(bool $formatted): string
{
    $not = $formatted ? '! ' : '!';

    return "<?php\n\nclass Example\n{\n    public function flip(bool \$value): bool\n    {\n        return {$not}\$value;\n    }\n}\n";
}

function writeHookFile(string $repository, string $path, string $contents): void
{
    File::ensureDirectoryExists(dirname("{$repository}/{$path}"));
    file_put_contents("{$repository}/{$path}", $contents);
}

function stageHookFile(string $repository, string $path, string $contents): void
{
    writeHookFile($repository, $path, $contents);
    runGit($repository, 'add', '--', $path);
}

function runPreCommitHook(string $repository): ProcessResult
{
    return Process::path($repository)
        ->env(isolatedGitEnvironment())
        ->timeout(120)
        ->run(['bash', GitHooks::DIRECTORY.'/pre-commit']);
}

test('passes without running Pint when no PHP file is staged', function (): void {
    stageHookFile($this->repository, 'README.md', "# Example\n");

    $result = runPreCommitHook($this->repository);

    expect($result->successful())->toBeTrue()
        ->and($result->output())->not->toContain('Checking code style');
});

test('passes when the staged PHP files match the code style', function (): void {
    stageHookFile($this->repository, 'app/Example.php', hookExampleClass(formatted: true));

    $result = runPreCommitHook($this->repository);

    expect($result->successful())->toBeTrue()
        ->and($result->errorOutput())->not->toContain('Commit aborted');
});

test('fails with the commands that fix the staged files', function (): void {
    stageHookFile($this->repository, 'app/Example.php', hookExampleClass(formatted: false));
    stageHookFile($this->repository, 'app/Other.php', str_replace('Example', 'Other', hookExampleClass(formatted: true)));

    $result = runPreCommitHook($this->repository);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('==> Commit aborted: Pint found code style issues in the staged changes.')
        ->and($result->errorOutput())->toContain("vendor/bin/pint \\\n        app/Example.php\n")
        ->and($result->errorOutput())->toContain("git add \\\n        app/Example.php\n")
        ->and($result->errorOutput())->not->toContain('app/Other.php')
        ->and($result->errorOutput())->not->toContain('unstaged changes');
});

test('checks a renamed file, which git lists as a rename rather than a change', function (): void {
    stageHookFile($this->repository, 'app/Example.php', hookExampleClass(formatted: true));
    runGit($this->repository, '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '--quiet', '--no-verify', '--message', 'initial');
    runGit($this->repository, 'mv', 'app/Example.php', 'app/Renamed.php');
    stageHookFile($this->repository, 'app/Renamed.php', hookExampleClass(formatted: false));

    $result = runPreCommitHook($this->repository);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain("vendor/bin/pint \\\n        app/Renamed.php\n");
});

test('prints a non-ASCII file name as typed, not quoted the way git lists it', function (): void {
    stageHookFile($this->repository, 'app/Café.php', hookExampleClass(formatted: false));

    $result = runPreCommitHook($this->repository);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain("git add \\\n        app/Café.php\n")
        ->and($result->errorOutput())->not->toContain('\\303');
});

test('checks the staged version of a file, not the working tree', function (): void {
    stageHookFile($this->repository, 'app/Example.php', hookExampleClass(formatted: false));
    writeHookFile($this->repository, 'app/Example.php', hookExampleClass(formatted: true));

    $result = runPreCommitHook($this->repository);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('these files also have unstaged changes')
        ->and($result->errorOutput())->toContain("only the formatting:\n\n        app/Example.php\n");
});

test('passes a well-formatted staged file whose unstaged edits are not', function (): void {
    stageHookFile($this->repository, 'app/Example.php', hookExampleClass(formatted: true));
    writeHookFile($this->repository, 'app/Example.php', hookExampleClass(formatted: false));

    expect(runPreCommitHook($this->repository)->successful())->toBeTrue();
});

test('skips the files Pint excludes by default, as CI does', function (): void {
    stageHookFile($this->repository, '_ide_helper.php', hookExampleClass(formatted: false));
    stageHookFile($this->repository, 'resources/views/welcome.blade.php', "<div>{{ !\$value }}</div>\n");

    expect(runPreCommitHook($this->repository)->successful())->toBeTrue();
});

test('applies the staged pint.json', function (): void {
    stageHookFile($this->repository, 'pint.json', json_encode(['preset' => 'psr12']));
    stageHookFile($this->repository, 'app/Example.php', hookExampleClass(formatted: false));

    expect(runPreCommitHook($this->repository)->successful())->toBeTrue();
});

test('says when Pint cannot parse a staged file', function (): void {
    stageHookFile($this->repository, 'app/Broken.php', "<?php\n\nclass Broken {\n    public function x( {\n}\n");

    $result = runPreCommitHook($this->repository);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('==> Commit aborted: Pint could not check the staged files.')
        ->and($result->errorOutput())->not->toContain('found code style issues');
});

test('explains how to install Pint when it is missing', function (): void {
    unlink($this->repository.'/vendor/bin/pint');
    stageHookFile($this->repository, 'app/Example.php', hookExampleClass(formatted: true));

    $result = runPreCommitHook($this->repository);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('==> Commit aborted: Pint is not installed.')
        ->and($result->errorOutput())->toContain('composer install');
});

test('the hook is a strict-mode bash script naming its regenerate command', function (): void {
    $hook = (new GitHooks)->preCommit(true);

    expect($hook->path)->toBe('.githooks/pre-commit')
        ->and($hook->executable)->toBeTrue()
        ->and($hook->contents)->toStartWith("#!/usr/bin/env bash\n")
        ->and($hook->contents)->toContain('set -euo pipefail')
        ->and($hook->contents)->toContain('regenerate with `php artisan generate:git-hooks`');
});

test('no hook without Pint', function (): void {
    expect((new GitHooks)->preCommit(false))->toBeNull();
});
