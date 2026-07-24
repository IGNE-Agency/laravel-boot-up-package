<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

use Closure;
use Igne\LaravelBootUp\Data\ServeContext;

/**
 * A single stage of the serve/deploy pipeline. Implementations are resolved
 * from the container, so collaborators arrive via constructor injection.
 */
interface Step
{
    public function handle(ServeContext $context, Closure $next): mixed;
}
