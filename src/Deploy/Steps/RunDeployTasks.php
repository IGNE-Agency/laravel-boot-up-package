<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Deploy\DeployTaskRunner;
use Igne\LaravelBootUp\Enums\DeployPhase;
use Igne\LaravelBootUp\Enums\ServeStage;
use InvalidArgumentException;

/**
 * Runs the host's project commands for one phase. Placed in the pipeline with
 * a phase suffix Laravel passes as the $phase argument, e.g.
 * `RunDeployTasks::class.':before'` (before migrations) or `':after'`.
 * The deploy phases `':before-deploy'` / `':after-deploy'` are also accepted
 * for pipelines that want the full four-phase model locally.
 */
#[Stage(ServeStage::Database)]
#[Group('deploy-tasks')]
final class RunDeployTasks implements Step
{
    public function __construct(private readonly DeployTaskRunner $runner) {}

    /**
     * $phase stays a string because the value arrives spread from the
     * pipeline entry's ':phase' suffix — the enum boundary is right here.
     */
    public function handle(ServeContext $context, Closure $next, string $phase = 'before'): mixed
    {
        $parsed = DeployPhase::tryFrom($phase)
            ?? throw new InvalidArgumentException("Unknown project command phase [{$phase}]; expected 'before-deploy', 'before', 'after' or 'after-deploy'.");

        $this->runner->run($parsed, $context);

        return $next($context);
    }
}
