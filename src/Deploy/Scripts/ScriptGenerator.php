<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy\Scripts;

/**
 * Renders a deployment script for a hosting platform. Register custom
 * platforms under config('boot-up.deploy.script_generators').
 */
interface ScriptGenerator
{
    public function key(): string;

    public function label(): string;

    public function generate(DeploymentPlan $plan): string;
}
