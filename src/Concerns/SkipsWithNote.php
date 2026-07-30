<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Concerns;

use Closure;
use Igne\LaravelBootUp\Data\ServeContext;

/**
 * The standard step skip prologue: explain in a dim note, pass the
 * context to the next pipe.
 */
trait SkipsWithNote
{
    private function skipStep(string $reason, ServeContext $context, Closure $next): mixed
    {
        terminal()->note($reason);

        return $next($context);
    }
}
