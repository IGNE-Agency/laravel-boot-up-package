<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tools\Steps;

use Closure;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;
use Igne\LaravelBootUp\Tools\Tool;
use Igne\LaravelBootUp\Tools\ToolManager;
use Igne\LaravelBootUp\Tools\ToolRegistry;
use Igne\LaravelBootUp\Tools\ToolsConfig;
use Igne\LaravelBootUp\Tools\VersionConstraint;

/**
 * Ensures every configured tool — plus whatever the selected server needs —
 * is installed and satisfies its version constraint.
 */
final class EnsureToolsReady implements Step
{
    public function __construct(
        private readonly ToolsConfig $config,
        private readonly ToolRegistry $registry,
        private readonly ToolManager $manager,
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
        }

        return $next($context);
    }
}
