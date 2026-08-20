<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Unit\Boot\Fixtures;

use Closure;
use Igne\LaravelBootUp\Attributes\Label;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Enums\BootStage;

/**
 * A third-party step that names itself, the way the shipped ones do.
 */
#[Stage(BootStage::Database)]
#[Label('Seeding the search index')]
final class LabelledStep implements Step
{
    public function handle(BootContext $context, Closure $next): mixed
    {
        return $next($context);
    }
}
