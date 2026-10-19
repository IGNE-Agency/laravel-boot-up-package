<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Pipelines\GitHooks;
use Igne\LaravelBootUp\Pipelines\GitHooksPath;
use Igne\LaravelBootUp\Services\GeneratedFilePublisher;

final class GenerateGitHooksCommand extends BootUpCommand
{
    protected $signature = 'generate:git-hooks
        {--force : Overwrite an existing .githooks/pre-commit without asking}';

    protected $description = 'Install git hooks that run the pipeline\'s checks locally before a commit';

    public function handle(ComposerJson $composerJson, GitHooksPath $hooksPath, GeneratedFilePublisher $publisher): int
    {
        $this->announce('Installing git hooks...');

        if (! $hooksPath->insideWorkTree()) {
            terminal()->error('Not a git repository — run this from the root of a git working tree.');

            return self::FAILURE;
        }

        $pint = $composerJson->requires('laravel/pint') || $composerJson->requiresDev('laravel/pint');

        $hook = (new GitHooks)->preCommit($pint);

        if ($hook === null) {
            return $this->skip('Install laravel/pint to enable the pre-commit lint hook — nothing to install yet.');
        }

        // A declined overwrite keeps the hook that is already there — still
        // the project's hook, so it is enabled all the same.
        $publisher->publish([$hook], (bool) $this->option('force'));

        $this->pointGitAtHooksPath($hooksPath);

        $directory = GitHooks::DIRECTORY;
        terminal()->note([
            "Commit {$directory}/ so your team shares the hook.",
            "php artisan app:up enables it on every other clone (or: git config core.hooksPath {$directory}).",
        ]);

        return $this->done('Git hooks installed.');
    }

    /**
     * Asked for explicitly, so unlike app:up this replaces whatever git ran
     * before — and says so.
     */
    private function pointGitAtHooksPath(GitHooksPath $hooksPath): void
    {
        $directory = GitHooks::DIRECTORY;

        if ($hooksPath->enabled()) {
            terminal()->success("git config core.hooksPath already points at {$directory}.");

            return;
        }

        $replaced = $hooksPath->otherHooks();

        if (! $hooksPath->enable()) {
            terminal()->warning("Could not point git at {$directory} — run: git config core.hooksPath {$directory}");

            return;
        }

        terminal()->success("Pointed git config core.hooksPath at {$directory}.");

        if ($replaced !== null) {
            terminal()->warning("Git no longer runs {$replaced}.");
        }
    }
}
