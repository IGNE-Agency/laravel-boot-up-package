<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Deploy\Steps;

use Closure;
use Igne\LaravelBootstrap\Deploy\ProjectCommandRunner;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\Step;

/**
 * Appears twice in the pipeline: `RunProjectCommands::class.':before'` and
 * `RunProjectCommands::class.':after'` — Laravel's pipeline passes the suffix
 * as the $phase argument.
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
