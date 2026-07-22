<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy\Scripts;

use Igne\LaravelBootUp\Support\Lines;

/**
 * Renders a deployment script for a hosting platform. Register custom
 * platforms under config('boot-up.deploy.script_generators').
 */
interface ScriptGenerator
{
    public function key(): string;

    public function label(): string;

    /**
     * The script as a Lines document: render() gives the plain text written
     * to files, while its per-line kinds drive coloured terminal output.
     */
    public function generate(DeploymentPlan $plan): Lines;
}
