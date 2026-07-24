<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Data\ShellCommand;
use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Pipelines\GitHooks;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Services\GeneratedFilePublisher;

final class GenerateGitHooksCommand extends BootUpCommand
{
    protected $signature = 'generate:git-hooks
        {--force : Overwrite an existing .githooks/pre-commit without asking}';

    protected $description = 'Install git hooks that run the pipeline\'s checks locally before a commit';

    public function handle(ComposerJson $composerJson, ProcessRunner $processes, GeneratedFilePublisher $publisher): int
    {
        $this->announce('Installing git hooks...');

        if (! $this->insideGitWorkTree($processes)) {
            terminal()->error('Not a git repository — run this from the root of a git working tree.');

            return self::FAILURE;
        }

        $pint = $composerJson->requires('laravel/pint') || $composerJson->requiresDev('laravel/pint');

        $hook = (new GitHooks)->preCommit($pint);

        if ($hook === null) {
            return $this->skip('Install laravel/pint to enable the pre-commit lint hook — nothing to install yet.');
        }

        if (! $publisher->publish([$hook], (bool) $this->option('force'))) {
            return self::SUCCESS;
        }

        $this->pointGitAtHooksPath($processes);

        $directory = GitHooks::DIRECTORY;
        terminal()->note("Commit {$directory}/ so your team shares the hook.");

        return $this->done('Git hooks installed.');
    }

    private function insideGitWorkTree(ProcessRunner $processes): bool
    {
        return $processes->runSilently(
            ShellCommand::make(['git', 'rev-parse', '--is-inside-work-tree'])
                ->inDirectory($this->laravel->basePath()),
        )->successful();
    }

    private function pointGitAtHooksPath(ProcessRunner $processes): void
    {
        $processes->runSilently(
            ShellCommand::make(['git', 'config', 'core.hooksPath', GitHooks::DIRECTORY])
                ->inDirectory($this->laravel->basePath()),
        );

        $directory = GitHooks::DIRECTORY;
        terminal()->success("Pointed git config core.hooksPath at {$directory}.");
    }
}
