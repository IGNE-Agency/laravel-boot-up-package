<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Console;

use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Pipelines\GeneratedFile;
use Igne\LaravelBootUp\Pipelines\GitHooks;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;
use Igne\LaravelBootUp\Support\AtomicFile;

final class GenerateGitHooksCommand extends BootUpCommand
{
    protected $signature = 'generate:git-hooks
        {--force : Overwrite an existing .githooks/pre-commit without asking}';

    protected $description = 'Install git hooks that run the pipeline\'s checks locally before a commit';

    public function perform(ComposerJson $composerJson, ProcessRunner $processes): int
    {
        terminal()->intro('Installing git hooks...');

        if (! $this->insideGitWorkTree($processes)) {
            terminal()->error('Not a git repository — run this from the root of a git working tree.');

            return self::FAILURE;
        }

        $pint = $composerJson->requires('laravel/pint') || $composerJson->requiresDev('laravel/pint');

        $hook = (new GitHooks)->preCommit($pint);

        if ($hook === null) {
            terminal()->note('Install laravel/pint to enable the pre-commit lint hook — nothing to install yet.');

            return self::SUCCESS;
        }

        if (! $this->confirmOverwrite($hook)) {
            return self::SUCCESS;
        }

        $this->write($hook);

        $processes->runSilently(
            ShellCommand::make(['git', 'config', 'core.hooksPath', GitHooks::DIRECTORY])
                ->inDirectory($this->laravel->basePath()),
        );
        terminal()->success('Pointed git config core.hooksPath at '.GitHooks::DIRECTORY.'.');

        terminal()->note('Commit '.GitHooks::DIRECTORY.'/ so your team shares the hook.');
        terminal()->outro('Git hooks installed.');

        return self::SUCCESS;
    }

    private function insideGitWorkTree(ProcessRunner $processes): bool
    {
        return $processes->runSilently(
            ShellCommand::make(['git', 'rev-parse', '--is-inside-work-tree'])
                ->inDirectory($this->laravel->basePath()),
        )->successful();
    }

    private function confirmOverwrite(GeneratedFile $hook): bool
    {
        if ($this->option('force') || ! is_file($this->laravel->basePath($hook->path))) {
            return true;
        }

        $confirmed = terminal()->confirm("{$hook->path} already exists. Overwrite it?", default: false);

        if (! $confirmed) {
            terminal()->warning('Nothing written — declined to overwrite the existing hook.');
        }

        return $confirmed;
    }

    private function write(GeneratedFile $hook): void
    {
        $path = $this->laravel->basePath($hook->path);

        AtomicFile::write($path, $hook->contents);

        if ($hook->executable) {
            chmod($path, 0755);
        }

        terminal()->success("Wrote {$hook->path}.");
    }
}
