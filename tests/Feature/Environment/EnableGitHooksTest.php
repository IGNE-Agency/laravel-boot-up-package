<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Environment\Steps\EnableGitHooks;
use Igne\LaravelBootUp\Pipelines\GitHooksPath;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    // The git the package spawns inherits $_ENV.
    $_ENV = [...$_ENV, ...isolatedGitEnvironment()];

    $this->repository = gitRepository();

    $this->step = fn (?string $basePath = null): EnableGitHooks => new EnableGitHooks(new GitHooksPath(
        new ProcessRunner(
            processes: app(Factory::class),
            ledger: new ProcessLedger($this->repository.'/.processes.json'),
            logDirectory: $this->repository.'/.logs',
        ),
        $basePath ?? $this->repository,
    ));

    // Unset reads as '' — git exits 1 for a missing key, so no runGit().
    $this->hooksPathConfig = fn (): string => trim(Process::path($this->repository)
        ->env(isolatedGitEnvironment())
        ->run(['git', 'config', '--get', 'core.hooksPath'])
        ->output());

    Prompt::fake();
});

afterEach(function (): void {
    foreach (array_keys(isolatedGitEnvironment()) as $name) {
        unset($_ENV[$name]);
    }

    File::deleteDirectory($this->repository);
});

function addTrackedHookFor(string $directory): void
{
    File::ensureDirectoryExists("{$directory}/.githooks");
    file_put_contents("{$directory}/.githooks/pre-commit", "#!/usr/bin/env bash\n");
}

test('enables the tracked hooks and passes the context on', function (): void {
    addTrackedHookFor($this->repository);
    $context = new BootContext(new BootOptions);

    expect(($this->step)()->handle($context, fn ($passed) => $passed))->toBe($context)
        ->and(($this->hooksPathConfig)())->toBe('.githooks');

    Prompt::assertStrippedOutputContains('Git hooks enabled');
});

test('notes hooks that are already enabled', function (): void {
    addTrackedHookFor($this->repository);
    runGit($this->repository, 'config', 'core.hooksPath', '.githooks');

    ($this->step)()->handle(new BootContext(new BootOptions), fn ($passed) => $passed);

    Prompt::assertStrippedOutputContains('Git hooks already enabled.');
});

test('leaves a hooks directory another tool manages alone', function (): void {
    addTrackedHookFor($this->repository);
    runGit($this->repository, 'config', 'core.hooksPath', '.husky/_');

    ($this->step)()->handle(new BootContext(new BootOptions), fn ($passed) => $passed);

    expect(($this->hooksPathConfig)())->toBe('.husky/_');
    Prompt::assertStrippedOutputContains('Git already runs the hooks in .husky/_');
    Prompt::assertStrippedOutputContains('git config core.hooksPath .githooks');
});

test('leaves hooks installed into .git/hooks alone', function (): void {
    addTrackedHookFor($this->repository);
    file_put_contents($this->repository.'/.git/hooks/pre-commit', "#!/bin/sh\n");
    chmod($this->repository.'/.git/hooks/pre-commit', 0755);

    ($this->step)()->handle(new BootContext(new BootOptions), fn ($passed) => $passed);

    expect(($this->hooksPathConfig)())->toBe('');
    Prompt::assertStrippedOutputContains('Git already runs the hooks in .git/hooks (pre-commit)');
});

test('points a project without tracked hooks at the generator', function (): void {
    $context = new BootContext(new BootOptions);

    expect(($this->step)()->handle($context, fn ($passed) => $passed))->toBe($context)
        ->and(($this->hooksPathConfig)())->toBe('');

    Prompt::assertStrippedOutputContains('php artisan generate:git-hooks');
});

test('skips a project that is not a git repository', function (): void {
    $outside = sys_get_temp_dir().'/boot-up-no-git-'.bin2hex(random_bytes(4));
    addTrackedHookFor($outside);

    try {
        ($this->step)($outside)->handle(new BootContext(new BootOptions), fn ($passed) => $passed);

        Prompt::assertStrippedOutputContains('Not a git repository');
    } finally {
        File::deleteDirectory($outside);
    }
});
