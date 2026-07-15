<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Serve;

use Closure;

/**
 * A single stage of the serve/deploy pipeline. Implementations are resolved
 * from the container, so collaborators arrive via constructor injection.
 */
interface Step
{
    public function handle(ServeContext $context, Closure $next): mixed;
}
