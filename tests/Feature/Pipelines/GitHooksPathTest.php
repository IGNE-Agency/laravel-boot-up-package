<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Pipelines\GitHooksPath;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    // The git the package spawns inherits $_ENV.
    $_ENV = [...$_ENV, ...isolatedGitEnvironment()];

    $this->repository = gitRepository();

    $this->hooksPath = fn (?string $basePath = null): GitHooksPath => new GitHooksPath(
        new ProcessRunner(
            processes: app(Factory::class),
            ledger: new ProcessLedger($this->repository.'/.processes.json'),
            logDirectory: $this->repository.'/.logs',
        ),
        $basePath ?? $this->repository,
    );
});

afterEach(function (): void {
    foreach (array_keys(isolatedGitEnvironment()) as $name) {
        unset($_ENV[$name]);
    }

    File::deleteDirectory($this->repository);
});

function addTrackedHook(string $repository): void
{
    File::ensureDirectoryExists("{$repository}/.githooks");
    file_put_contents("{$repository}/.githooks/pre-commit", "#!/usr/bin/env bash\n");
}

test('has hooks only once the tracked directory holds one', function (): void {
    expect(($this->hooksPath)()->hasHooks())->toBeFalse();

    File::ensureDirectoryExists($this->repository.'/.githooks');
    expect(($this->hooksPath)()->hasHooks())->toBeFalse();

    addTrackedHook($this->repository);
    expect(($this->hooksPath)()->hasHooks())->toBeTrue();
});

test('knows whether it is inside a git work tree', function (): void {
    $outside = sys_get_temp_dir().'/boot-up-no-git-'.bin2hex(random_bytes(4));
    mkdir($outside);

    try {
        expect(($this->hooksPath)()->insideWorkTree())->toBeTrue()
            ->and(($this->hooksPath)($outside)->insideWorkTree())->toBeFalse();
    } finally {
        File::deleteDirectory($outside);
    }
});

test('enables the tracked hooks in the repository config', function (): void {
    addTrackedHook($this->repository);
    $hooksPath = ($this->hooksPath)();

    expect($hooksPath->enabled())->toBeFalse()
        ->and($hooksPath->otherHooks())->toBeNull()
        ->and($hooksPath->enable())->toBeTrue()
        ->and($hooksPath->enabled())->toBeTrue()
        ->and(trim(runGit($this->repository, 'config', '--local', '--get', 'core.hooksPath')))->toBe('.githooks');
});

test('any spelling of the tracked directory counts as enabled', function (string $path): void {
    addTrackedHook($this->repository);
    runGit($this->repository, 'config', 'core.hooksPath', str_replace('{repository}', $this->repository, $path));

    expect(($this->hooksPath)()->enabled())->toBeTrue()
        ->and(($this->hooksPath)()->otherHooks())->toBeNull();
})->with(['.githooks', './.githooks/', '{repository}/.githooks']);

test('names another hooks directory that enabling would replace', function (): void {
    addTrackedHook($this->repository);
    runGit($this->repository, 'config', 'core.hooksPath', '.husky/_');

    expect(($this->hooksPath)()->enabled())->toBeFalse()
        ->and(($this->hooksPath)()->otherHooks())->toBe('the hooks in .husky/_');
});

test('names executable hooks installed into .git/hooks, never the samples', function (): void {
    addTrackedHook($this->repository);
    $hooks = $this->repository.'/.git/hooks';
    File::ensureDirectoryExists($hooks);

    expect(($this->hooksPath)()->otherHooks())->toBeNull();

    file_put_contents("{$hooks}/pre-commit", "#!/bin/sh\n");
    chmod("{$hooks}/pre-commit", 0755);
    file_put_contents("{$hooks}/pre-push", "#!/bin/sh\n");
    chmod("{$hooks}/pre-push", 0644);
    file_put_contents("{$hooks}/commit-msg.sample", "#!/bin/sh\n");
    chmod("{$hooks}/commit-msg.sample", 0755);

    expect(($this->hooksPath)()->otherHooks())->toBe('the hooks in .git/hooks (pre-commit)');
});
