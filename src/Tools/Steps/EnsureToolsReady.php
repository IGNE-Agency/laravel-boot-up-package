<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Tools\Steps;

use Closure;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\Step;
use Igne\LaravelBootstrap\Tools\Tool;
use Igne\LaravelBootstrap\Tools\ToolManager;
use Igne\LaravelBootstrap\Tools\ToolRegistry;
use Igne\LaravelBootstrap\Tools\ToolsConfig;
use Igne\LaravelBootstrap\Tools\VersionConstraint;

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
