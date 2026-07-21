<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy\Steps;

use Closure;
use Igne\LaravelBootUp\Deploy\ProjectCommandRunner;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;

/**
 * Runs the host's project commands for one phase. Placed in the pipeline with
 * a phase suffix Laravel passes as the $phase argument, e.g.
 * `RunProjectCommands::class.':before'` (before migrations) or `':after'`.
 * The deploy phases `':before-deploy'` / `':after-deploy'` are also accepted
 * for pipelines that want the full four-phase model locally.
 */
final class RunProjectCommands implements Step
{
    public function __construct(private readonly ProjectCommandRunner $runner) {}

    public function handle(ServeContext $context, Closure $next, string $phase = 'before'): mixed
    {
        $this->runner->run($phase, $context);

        return $next($context);
    }
}
