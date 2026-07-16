<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools\Steps;

use Closure;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;
use Igne\LaravelBootUp\Tools\Tool;
use Igne\LaravelBootUp\Tools\ToolManager;
use Igne\LaravelBootUp\Tools\ToolRegistry;
use Igne\LaravelBootUp\Tools\ToolsConfig;
use Igne\LaravelBootUp\Tools\VersionConstraint;

/**
 * Ensures every configured tool — plus whatever the selected server needs,
 * plus the selected frontend package manager — is installed and satisfies
 * its version constraint.
 */
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
        $covered = [];

        foreach ($this->config->required as $id => $constraint) {
            $this->manager->ensure(
                $this->registry->installerFor($id),
                VersionConstraint::of((string) $constraint),
            );

            $covered[$id] = true;
        }

        foreach ($context->server?->requiredTools() ?? [] as $tool) {
            $id = $tool instanceof Tool ? $tool->value : $tool;

            if (isset($covered[$id])) {
                continue;
            }

            $this->manager->ensure(
                $this->registry->installerFor($id),
                VersionConstraint::wildcard(),
            );

            $covered[$id] = true;
        }

        $this->ensurePackageManager($context, $covered);

        return $next($context);
    }

    /**
     * The frontend steps call the selected package manager on the host, so
     * it must exist there — unless assets are skipped, there is nothing to
     * install, or the server wraps the binary (Sail runs it in-container).
     *
     * @param  array<string, true>  $covered
     */
    private function ensurePackageManager(ServeContext $context, array $covered): void
    {
        if (! $context->options->withAssets || ! $this->packageJson->exists()) {
            return;
        }

        $manager = $this->selector->selected();

        if (isset($covered[$manager->tool()->value])) {
            return;
        }

        if ($context->server?->commandRewrites()->wraps($manager->binary()) === true) {
            return;
        }

        $this->manager->ensure(
            $this->registry->installerFor($manager->tool()->value),
            VersionConstraint::wildcard(),
        );
    }
}
