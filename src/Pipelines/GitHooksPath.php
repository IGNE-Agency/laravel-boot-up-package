<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Pipelines;

use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Illuminate\Contracts\Process\ProcessResult;

/**
 * Whether git runs the project's tracked hooks, and the switch that makes it.
 *
 * Git never runs hooks from the repository on its own: every clone has to
 * point core.hooksPath at the tracked directory once. generate:git-hooks does
 * that for whoever adds the hooks, and app:up for everyone who clones after.
 *
 * core.hooksPath replaces git's hooks directory rather than adding to it, so
 * enabling the tracked hooks switches off whatever git ran before — another
 * manager's directory (Husky) or hooks installed into .git/hooks (GrumPHP,
 * CaptainHook, a hand-written one). otherHooks() names those so callers can
 * leave them alone, or at least say what they replaced.
 */
final class GitHooksPath
{
    public function __construct(
        private readonly ProcessRunner $processes,
        private readonly string $basePath,
    ) {}

    /**
     * Whether the project has a tracked hooks directory with hooks in it.
     */
    public function hasHooks(): bool
    {
        return array_filter(glob($this->trackedDirectory().'/*') ?: [], is_file(...)) !== [];
    }

    public function insideWorkTree(): bool
    {
        return $this->git('rev-parse', '--is-inside-work-tree')->successful();
    }

    /**
     * Whether git already runs the tracked hooks.
     */
    public function enabled(): bool
    {
        return $this->pointsAtTrackedDirectory($this->configuredPath());
    }

    /**
     * The hooks git runs now that enabling the tracked ones would switch off,
     * described for a sentence — or null when there are none.
     */
    public function otherHooks(): ?string
    {
        $configured = $this->configuredPath();

        if ($configured !== null) {
            return $this->pointsAtTrackedDirectory($configured) ? null : "the hooks in {$configured}";
        }

        $installed = $this->installedHooks();

        return $installed === [] ? null : 'the hooks in .git/hooks ('.implode(', ', $installed).')';
    }

    /**
     * Point this clone at the tracked hooks. Local config only, so nothing
     * changes for other repositories on the machine.
     */
    public function enable(): bool
    {
        return $this->git('config', 'core.hooksPath', GitHooks::DIRECTORY)->successful();
    }

    /**
     * core.hooksPath as git resolves it here — from any config scope, so a
     * global hooks directory counts too — or null when it is not set.
     */
    private function configuredPath(): ?string
    {
        $result = $this->git('config', '--get', 'core.hooksPath');
        $path = trim($result->output());

        return $result->successful() && $path !== '' ? $path : null;
    }

    /**
     * Compared by real path, so ".githooks", "./.githooks/" and an absolute
     * path to the same directory all count.
     */
    private function pointsAtTrackedDirectory(?string $path): bool
    {
        if ($path === null) {
            return false;
        }

        $resolved = realpath(str_starts_with($path, '/') ? $path : "{$this->basePath}/{$path}");
        $tracked = realpath($this->trackedDirectory());

        return $resolved !== false && $resolved === $tracked;
    }

    /**
     * The hooks in git's default hooks directory that git would run — the
     * executable ones, minus the samples every `git init` writes.
     *
     * @return list<string>
     */
    private function installedHooks(): array
    {
        $result = $this->git('rev-parse', '--git-path', 'hooks');
        $directory = trim($result->output());

        if (! $result->successful() || $directory === '') {
            return [];
        }

        $directory = str_starts_with($directory, '/') ? $directory : "{$this->basePath}/{$directory}";

        return collect(glob("{$directory}/*") ?: [])
            ->filter(fn (string $hook): bool => is_file($hook) && is_executable($hook) && ! str_ends_with($hook, '.sample'))
            ->map(fn (string $hook): string => basename($hook))
            ->values()
            ->all();
    }

    private function trackedDirectory(): string
    {
        return "{$this->basePath}/".GitHooks::DIRECTORY;
    }

    private function git(string ...$arguments): ProcessResult
    {
        return $this->processes->runSilently(
            CommandLine::make(['git', ...$arguments])->inDirectory($this->basePath),
        );
    }
}
