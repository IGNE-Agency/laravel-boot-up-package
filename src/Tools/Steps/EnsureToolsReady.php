<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Config\ToolsConfig;
use Igne\LaravelBootUp\Contracts\RequiresTools;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ToolOutcome;
use Igne\LaravelBootUp\Data\VersionConstraint;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Enums\Tool;
use Igne\LaravelBootUp\Enums\ToolStatus;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Tools\ToolManager;
use Igne\LaravelBootUp\Tools\ToolRegistry;

/**
 * Ensures every configured tool — plus whatever the selected server needs,
 * plus the selected frontend package manager — is installed and satisfies
 * its version constraint. Quiet successes are bundled into one summary;
 * installs, updates and warnings printed during the run stay where they are.
 */
#[Stage(ServeStage::Tools)]
#[Group('tools')]
final class EnsureToolsReady implements Step
{
    public function __construct(
        private readonly ToolsConfig $config,
        private readonly ToolRegistry $registry,
        private readonly ToolManager $manager,
        private readonly PackageManagerSelector $selector,
        private readonly PackageJson $packageJson,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        $this->summarize($this->outcomes($context));

        return $next($context);
    }

    /**
     * @return list<ToolOutcome>
     */
    private function outcomes(ServeContext $context): array
    {
        $covered = [];
        $outcomes = [];

        foreach ($this->requiredConstraints($context) as $id => $constraint) {
            if (isset($covered[$id])) {
                continue;
            }

            $outcomes[] = $this->manager->ensure($this->registry->installerFor($id), $constraint);
            $covered[$id] = true;
        }

        $outcomes[] = $this->ensurePackageManager($context, $covered);

        return array_values(array_filter($outcomes));
    }

    /**
     * Configured tools with their constraints first, then the server's
     * required tools as wildcards — the first occurrence of an id wins.
     *
     * @return iterable<string, VersionConstraint>
     */
    private function requiredConstraints(ServeContext $context): iterable
    {
        foreach ($this->config->required as $id => $constraint) {
            yield $id => VersionConstraint::of((string) $constraint);
        }

        $required = $context->server instanceof RequiresTools ? $context->server->requiredTools() : [];

        foreach ($required as $tool) {
            yield ($tool instanceof Tool ? $tool->value : $tool) => VersionConstraint::wildcard();
        }
    }

    /**
     * The frontend steps call the selected package manager on the host, so
     * it must exist there — unless assets are skipped, there is nothing to
     * install, or the server wraps the binary (Sail runs it in-container).
     *
     * @param  array<string, true>  $covered
     */
    private function ensurePackageManager(ServeContext $context, array $covered): ?ToolOutcome
    {
        if (! $context->options->withAssets || ! $this->packageJson->exists()) {
            return null;
        }

        $manager = $this->selector->selected();

        if (isset($covered[$manager->tool()->value])) {
            return null;
        }

        if ($context->commandRewrites()?->wraps($manager->binary()) === true) {
            return null;
        }

        return $this->manager->ensure(
            $this->registry->installerFor($manager->tool()->value),
            VersionConstraint::wildcard(),
        );
    }

    /**
     * @param  list<ToolOutcome>  $outcomes
     */
    private function summarize(array $outcomes): void
    {
        if ($outcomes === []) {
            return;
        }

        terminal()->summary(
            'Dependencies ready',
            collect($outcomes)->map(fn (ToolOutcome $outcome): string => $outcome->describe())->all(),
            $this->footer($outcomes),
        );
    }

    /**
     * Accurate wording: "all installed" only when every check was quietly
     * satisfied; installs/updates and unverified tools are called out.
     *
     * @param  list<ToolOutcome>  $outcomes
     */
    private function footer(array $outcomes): string
    {
        $of = fn (ToolStatus $status): int => collect($outcomes)
            ->filter(fn (ToolOutcome $outcome): bool => $outcome->status === $status)
            ->count();

        $unverified = $of(ToolStatus::Unverified);

        if ($unverified > 0) {
            $total = \count($outcomes);

            return "{$unverified} of {$total} dependencies could not be verified — boot continues (see warnings above).";
        }

        $changed = $of(ToolStatus::Installed) + $of(ToolStatus::Updated);

        if ($changed > 0) {
            return "All dependencies are ready — {$changed} installed or updated during boot.";
        }

        if ($of(ToolStatus::SkippedSelfUpdating) > 0) {
            return 'All dependencies are ready.';
        }

        return 'All required dependencies are installed.';
    }
}
