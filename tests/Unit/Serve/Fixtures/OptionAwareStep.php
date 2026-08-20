<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Unit\Serve\Fixtures;

use Closure;
use Igne\LaravelBootUp\Contracts\DescribesProgress;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;

/**
 * A third-party step whose wording depends on the run, and on its own
 * `Class:parameter` argument.
 */
final class OptionAwareStep implements DescribesProgress, Step
{
    public function handle(ServeContext $context, Closure $next): mixed
    {
        return $next($context);
    }

    public static function progressLabel(ServeOptions $options, array $parameters): string
    {
        $target = $parameters[0] ?? 'everything';

        return $options->fresh ? "Rebuilding {$target}" : "Refreshing {$target}";
    }
}
