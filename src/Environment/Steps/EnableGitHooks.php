<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Environment\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Label;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Concerns\SkipsWithNote;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Enums\BootStage;
use Igne\LaravelBootUp\Pipelines\GitHooks;
use Igne\LaravelBootUp\Pipelines\GitHooksPath;

/**
 * Turn on the project's tracked git hooks for whoever runs app:up.
 *
 * generate:git-hooks commits the hooks and enables them for the person who
 * ran it — but git never runs hooks from the repository by itself, so every
 * other clone needs core.hooksPath set once. Without this step a teammate's
 * commits skip the hook entirely and the CI lint job is the first to object.
 *
 * Hooks git already runs from somewhere else are left alone: switching them
 * off is not a side effect a setup command should have.
 */
#[Stage(BootStage::Prepare)]
#[Group('prepare')]
#[Label('Enabling the git hooks')]
final class EnableGitHooks implements Step
{
    use SkipsWithNote;

    public function __construct(private readonly GitHooksPath $hooks) {}

    public function handle(BootContext $context, Closure $next): mixed
    {
        $directory = GitHooks::DIRECTORY;

        if (! $this->hooks->hasHooks()) {
            return $this->skipStep("No git hooks in {$directory}/ — add the Pint pre-commit hook with: php artisan generate:git-hooks", $context, $next);
        }

        if (! $this->hooks->insideWorkTree()) {
            return $this->skipStep("Not a git repository — the hooks in {$directory}/ stay inactive.", $context, $next);
        }

        if ($this->hooks->enabled()) {
            return $this->skipStep('Git hooks already enabled.', $context, $next);
        }

        $other = $this->hooks->otherHooks();

        if ($other !== null) {
            terminal()->warning("Git already runs {$other}, so the hooks in {$directory}/ stay inactive. To use them instead: git config core.hooksPath {$directory}");

            return $next($context);
        }

        if (! $this->hooks->enable()) {
            terminal()->warning("Could not enable the hooks in {$directory}/ — run: git config core.hooksPath {$directory}");

            return $next($context);
        }

        terminal()->success("Git hooks enabled — git now runs the hooks in {$directory}/.");

        return $next($context);
    }
}
