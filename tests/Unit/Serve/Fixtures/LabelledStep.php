<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Unit\Serve\Fixtures;

use Closure;
use Igne\LaravelBootUp\Attributes\Label;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Enums\ServeStage;

/**
 * A third-party step that names itself, the way the shipped ones do.
 */
#[Stage(ServeStage::Database)]
#[Label('Seeding the search index')]
final class LabelledStep implements Step
{
    public function handle(ServeContext $context, Closure $next): mixed
    {
        return $next($context);
    }
}
