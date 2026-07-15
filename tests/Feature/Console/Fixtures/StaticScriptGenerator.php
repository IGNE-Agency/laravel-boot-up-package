<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Tests\Feature\Console\Fixtures;

use Igne\LaravelBootstrap\Deploy\Scripts\DeploymentPlan;
use Igne\LaravelBootstrap\Deploy\Scripts\ScriptGenerator;

/**
 * A project-registered custom platform, as the extension API allows.
 */
final class StaticScriptGenerator implements ScriptGenerator
{
    public function key(): string
    {
        return 'static';
    }

    public function label(): string
    {
        return 'Static Platform';
    }

    public function generate(DeploymentPlan $plan): string
    {
        return "static-script for {$plan->environment->value}\n";
    }
}
