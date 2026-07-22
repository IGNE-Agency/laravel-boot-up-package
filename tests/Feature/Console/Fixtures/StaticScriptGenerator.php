<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Console\Fixtures;

use Igne\LaravelBootUp\Deploy\Scripts\DeploymentPlan;
use Igne\LaravelBootUp\Deploy\Scripts\ScriptGenerator;
use Igne\LaravelBootUp\Support\Lines;

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

    public function generate(DeploymentPlan $plan): Lines
    {
        return Lines::make()->line("static-script for {$plan->environment->value}");
    }
}
