<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

use Igne\LaravelBootUp\Data\BootOptions;

/**
 * A step whose progress label depends on how the command was invoked —
 * installing versus updating, migrating versus rebuilding.
 *
 * Static because the plan is assembled from class strings: the steps are
 * resolved lazily, one at a time, long after the plan is printed. A step
 * with a fixed label uses the Label attribute instead.
 */
interface DescribesProgress
{
    /**
     * @param  list<string>  $parameters  the step's `Class:a,b` arguments
     */
    public static function progressLabel(BootOptions $options, array $parameters): string;
}
