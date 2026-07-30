<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

/**
 * The four project-command hooks, in execution order. The generated deploy
 * scripts and CI run every phase; the local pipelines run the migration
 * phases by default.
 */
enum DeployPhase: string
{
    case BeforeDeploy = 'before-deploy';
    case Before = 'before';
    case After = 'after';
    case AfterDeploy = 'after-deploy';
}
