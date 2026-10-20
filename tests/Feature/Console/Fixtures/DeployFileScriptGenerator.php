<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Console\Fixtures;

use Igne\LaravelBootUp\Contracts\ScriptGenerator;
use Igne\LaravelBootUp\Contracts\WritesDeployFile;
use Igne\LaravelBootUp\Data\DeploymentPlan;
use Igne\LaravelBootUp\Data\Lines;

/**
 * A project-registered custom platform that reads a committed deploy file,
 * proving the command's deploy-file behaviour is not fortrabbit-specific.
 */
final class DeployFileScriptGenerator implements ScriptGenerator, WritesDeployFile
{
    public const string PATH = '.static-platform/deploy.yml';

    public function key(): string
    {
        return 'static-file';
    }

    public function label(): string
    {
        return 'Static Platform With A Deploy File';
    }

    public function deployFilePath(): string
    {
        return self::PATH;
    }

    public function generate(DeploymentPlan $plan): Lines
    {
        return Lines::make()->line($plan->inTerminal
            ? "static-terminal for {$plan->environment->value}"
            : "static-deploy-file for {$plan->environment->value}");
    }
}
