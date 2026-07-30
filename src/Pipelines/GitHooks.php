<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Pipelines;

use Igne\LaravelBootUp\Data\GeneratedFile;
use Igne\LaravelBootUp\Data\Lines;

/**
 * Builds git hooks that run the pipeline's checks earlier — locally, before a
 * commit. Written into a tracked .githooks/ directory the command points git
 * at via core.hooksPath, so the whole team shares them once committed.
 */
final class GitHooks
{
    public const string DIRECTORY = '.githooks';

    /**
     * The pre-commit hook: lint the staged PHP files with Pint, the same check
     * the CI pipeline runs. Null when there is nothing to check yet (Pint is
     * not installed), so the command can skip cleanly.
     */
    public function preCommit(bool $lint): ?GeneratedFile
    {
        if (! $lint) {
            return null;
        }

        $script = $this->header('Lint the staged PHP files with Pint before each commit.')
            ->blank()
            ->comment('Run from the repository root, wherever git invokes the hook from.')
            ->line('cd "$(git rev-parse --show-toplevel)"')
            ->blank()
            ->comment('Only the PHP files staged for this commit — keeps the hook fast.')
            ->line("files=$(git diff --cached --name-only --diff-filter=ACM -- '*.php')")
            ->line('[ -z "$files" ] && exit 0')
            ->lineWithBreak('echo "==> Checking code style"')
            ->line('vendor/bin/pint --test -- $files');

        return new GeneratedFile(self::DIRECTORY.'/pre-commit', $script->render(), executable: true);
    }

    private function header(string $purpose, string ...$notes): Lines
    {
        return ScriptHeader::for('php artisan generate:git-hooks', $purpose, ...$notes);
    }
}
