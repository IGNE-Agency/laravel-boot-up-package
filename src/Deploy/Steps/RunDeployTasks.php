<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy\Steps;

use Closure;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Deploy\DeployTaskRunner;

/**
 * Runs the host's project commands for one phase. Placed in the pipeline with
 * a phase suffix Laravel passes as the $phase argument, e.g.
 * `RunDeployTasks::class.':before'` (before migrations) or `':after'`.
 * The deploy phases `':before-deploy'` / `':after-deploy'` are also accepted
 * for pipelines that want the full four-phase model locally.
 */
final class RunDeployTasks implements Step
{
    public function __construct(private readonly DeployTaskRunner $runner) {}

    public function handle(ServeContext $context, Closure $next, string $phase = 'before'): mixed
    {
        $this->runner->run($phase, $context);

        return $next($context);
    }
}
