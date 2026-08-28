<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Tests\Feature\Console\Fixtures;

use Closure;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Exceptions\ProcessException;

/**
 * Throws a known failure (a BootUpException) — the kind a real step raises
 * when something it manages goes wrong mid-boot.
 */
final class FailingStep implements Step
{
    public function handle(BootContext $context, Closure $next): mixed
    {
        throw ProcessException::pidNotCaptured('queue-worker');
    }
}
