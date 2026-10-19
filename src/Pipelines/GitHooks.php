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
     * Where Herd and Homebrew put PHP. GUI git clients run hooks without the
     * shell profile that adds these to PATH, so the hook looks here itself.
     */
    private const array PHP_DIRECTORIES = [
        '"$HOME/Library/Application Support/Herd/bin"',
        '"$HOME/.config/herd-lite/bin"',
        '/opt/homebrew/bin',
        '/usr/local/bin',
        '/home/linuxbrew/.linuxbrew/bin',
    ];

    /**
     * The pre-commit hook: lint the staged PHP files with Pint, the same check
     * the CI pipeline runs. Null when there is nothing to check yet (Pint is
     * not installed), so the command can skip cleanly.
     *
     * It checks the staged contents, not the working tree, so a file with
     * unstaged edits cannot pass here and fail in CI — and when it fails it
     * prints the exact commands that fix it, because a bare Pint report does
     * not tell anyone what to do next.
     */
    public function preCommit(bool $lint): ?GeneratedFile
    {
        if (! $lint) {
            return null;
        }

        $script = $this->header(
            'Check the code style of the staged PHP files with Pint before each commit.',
            'Pint checks exactly what is being committed, the way the CI lint job checks',
            'the repository, and a failure prints the commands that fix it.',
        )
            ->blank()
            ->comment('Run from the repository root, wherever git invokes the hook from.')
            ->line('root="$(git rev-parse --show-toplevel)"')
            ->line('cd "$root"')
            ->blank()
            ->comment('The PHP files this commit adds, changes or renames, as plain paths (git')
            ->comment('would otherwise quote names with non-ASCII characters).')
            ->line('staged_php() {')
            ->indent(2, fn (Lines $script) => $script
                ->line("git -c core.quotePath=false diff --cached --name-only --diff-filter=ACMR \"\$@\" -- '*.php'"))
            ->line('}')
            ->blank()
            ->comment('Nothing staged to check — keeps the hook fast for every other commit.')
            ->line('files="$(staged_php)"')
            ->line('[ -z "$files" ] && exit 0');

        $this->findPhp($script);
        $this->requireTools($script);
        $this->checkStagedContents($script);
        $this->explainFailure($script);

        return new GeneratedFile(self::DIRECTORY.'/pre-commit', $script->render(), executable: true);
    }

    private function findPhp(Lines $script): void
    {
        $directories = implode(" \\\n  ", self::PHP_DIRECTORIES);

        $script->blank()
            ->comment('GUI git clients run hooks without your shell profile, so PHP installed')
            ->comment('through Herd or Homebrew may be missing from PATH here.')
            ->line('if ! command -v php >/dev/null 2>&1; then')
            ->indent(2, fn (Lines $script) => $script
                ->line("for dir in \\\n  {$directories}; do")
                ->indent(2, fn (Lines $script) => $script
                    ->line('if [ -x "$dir/php" ]; then')
                    ->indent(2, fn (Lines $script) => $script
                        ->line('PATH="$dir:$PATH"')
                        ->line('break'))
                    ->line('fi'))
                ->line('done'))
            ->line('fi');
    }

    /**
     * Fail with directions rather than a "command not found": without PHP or
     * Pint there is nothing to check with.
     */
    private function requireTools(Lines $script): void
    {
        $script->blank()
            ->line('if ! command -v php >/dev/null 2>&1; then')
            ->indent(2, fn (Lines $script) => $this->abort($script, $this->missingPhp()))
            ->line('fi')
            ->blank()
            ->line('if [ ! -x vendor/bin/pint ]; then')
            ->indent(2, fn (Lines $script) => $this->abort($script, $this->missingPint()))
            ->line('fi');
    }

    /**
     * Sail is offered only where the project has it, since its script needs
     * no PHP on the host.
     */
    private function missingPhp(): FailureMessage
    {
        return $this->commitAborted('PHP is not on your PATH, so Pint cannot check the code style.')
            ->paragraph('Install PHP (Laravel Herd or Homebrew) and commit again.')
            ->when('[ -x vendor/bin/sail ]', fn (FailureMessage $message) => $message
                ->paragraph('Or check the code style through Sail and skip this hook for this commit:')
                ->commands(
                    'vendor/bin/sail bin pint --test',
                    'git commit --no-verify',
                ));
    }

    private function missingPint(): FailureMessage
    {
        return $this->commitAborted('Pint is not installed.')
            ->paragraph('Install the Composer dependencies and commit again:')
            ->commands('composer install');
    }

    /**
     * Pint runs on a copy of the index without explicit paths: explicit paths
     * bypass Pint's excludes, and CI never passes any.
     */
    private function checkStagedContents(Lines $script): void
    {
        $script->blank()
            ->comment('Check a copy of the staged contents rather than the working tree: only the')
            ->comment('staged version ends up in the commit, and a file may carry unstaged edits.')
            ->comment('Pint runs on the copy without explicit paths, exactly like the CI lint job,')
            ->comment('so its default excludes (_ide_helper files, Blade views) and pint.json apply.')
            ->line('work="$(mktemp -d)"')
            ->line("trap 'rm -rf \"\$work\"' EXIT")
            ->blank()
            ->line('{')
            ->indent(2, fn (Lines $script) => $script
                ->line('staged_php -z')
                ->line('git ls-files -z -- pint.json'))
            ->line('} | xargs -0 git checkout-index --prefix="$work/staged/" --')
            ->blank()
            ->comment('Run Pint inside one of the copies, keeping its cache out of the system tmp.')
            ->line('run_pint() {')
            ->indent(2, fn (Lines $script) => $script
                ->line('local copy="$1"')
                ->line('shift')
                ->line('(cd "$work/$copy" && "$root/vendor/bin/pint" --cache-file="$work/$copy.cache" "$@")'))
            ->line('}')
            ->lineWithBreak('echo "==> Checking code style"')
            ->line('if run_pint staged --test; then')
            ->indent(2, fn (Lines $script) => $script->line('exit 0'))
            ->line('fi');
    }

    private function explainFailure(Lines $script): void
    {
        $script->blank()
            ->comment('Let Pint fix a second copy: the files it changes are the ones to fix. This')
            ->comment('works with every Pint version, unlike its machine-readable reports.')
            ->line('cp -R "$work/staged" "$work/fixed"')
            ->line('run_pint fixed >/dev/null 2>&1 || true')
            ->blank()
            ->line(': > "$work/failed"')
            ->line('while IFS= read -r file; do')
            ->indent(2, fn (Lines $script) => $script
                ->line('cmp -s "$work/staged/$file" "$work/fixed/$file" || printf \'%s\\n\' "$file" >> "$work/failed"'))
            ->line('done <<< "$files"')
            ->blank()
            ->comment('Nothing Pint could fix, yet the check failed: Pint could not read a file.')
            ->line('if [ ! -s "$work/failed" ]; then')
            ->indent(2, fn (Lines $script) => $this->abort($script, $this->unreadableFiles()))
            ->line('fi')
            ->blank()
            ->comment('A command followed by the files to fix, one per line, ready to paste.')
            ->line('print_command() {')
            ->indent(2, fn (Lines $script) => $script
                ->line("printf '      %s' \"\$1\"")
                ->line('while IFS= read -r file; do')
                ->indent(2, fn (Lines $script) => $script->line("printf ' \\\\\\n        %s' \"\$file\""))
                ->line('done < "$work/failed"')
                ->line("printf '\\n'"))
            ->line('}')
            ->blank()
            ->comment('Files with unstaged edits as well, which `git add` would stage too.')
            ->line('partial="$(while IFS= read -r file; do')
            ->indent(2, fn (Lines $script) => $script->line('git diff --quiet -- "$file" || echo "        $file"'))
            ->line('done < "$work/failed")"')
            ->blank();

        $this->abort($script, $this->styleIssues());
    }

    private function unreadableFiles(): FailureMessage
    {
        return $this->commitAborted('Pint could not check the staged files.')
            ->paragraph(
                'Fix the errors Pint reported above (usually a PHP syntax error),',
                'stage the fix with `git add` and commit again.',
            );
    }

    /**
     * The fix as numbered steps, each command listing the files to fix —
     * print_command and $partial come from the script that embeds this.
     */
    private function styleIssues(): FailureMessage
    {
        return $this->commitAborted('Pint found code style issues in the staged changes.')
            ->paragraph('The CI lint job runs the same check (vendor/bin/pint --test). To fix it:')
            ->paragraph('1. Let Pint format the files:')
            ->blankLine()
            ->shell('print_command vendor/bin/pint')
            ->paragraph('2. Stage the formatted files:')
            ->blankLine()
            ->shell('print_command "git add"')
            ->paragraph('3. Commit again.')
            ->when('[ -n "$partial" ]', fn (FailureMessage $message) => $message
                ->paragraph(
                    'Careful: these files also have unstaged changes, which step 2 stages too.',
                    'Use `git add -p <file>` to stage only the formatting:',
                )
                ->blankLine()
                ->shell('echo "$partial"'))
            ->paragraph(
                'Format PHP with Pint only: editor formatters (PSR-12 presets, the PHP',
                'plugin for Prettier) and PHP Insights --fix produce a style Pint rejects.',
                'Run `vendor/bin/pint --dirty` before committing to format all your changes.',
            );
    }

    private function commitAborted(string $reason): FailureMessage
    {
        return FailureMessage::make()->headline("Commit aborted: {$reason}");
    }

    /**
     * Block the commit: the message goes to stderr, then `exit 1`.
     */
    private function abort(Lines $script, FailureMessage $message): void
    {
        $message->blankLine()->appendToStderr($script);
        $script->line('exit 1');
    }

    private function header(string $purpose, string ...$notes): Lines
    {
        return ScriptHeader::for('php artisan generate:git-hooks', $purpose, ...$notes);
    }
}
