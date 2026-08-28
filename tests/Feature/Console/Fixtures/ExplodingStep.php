<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Console\Fixtures;

use Closure;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\BootContext;
use RuntimeException;

/**
 * Throws something no step should: an exception outside the
 * BootUpException/process-exception families.
 */
final class ExplodingStep implements Step
{
    public function handle(BootContext $context, Closure $next): mixed
    {
        throw new RuntimeException('something exploded');
    }
}
