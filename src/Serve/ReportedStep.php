<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Closure;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\StepDescriptor;
use Illuminate\Contracts\Container\Container;

/**
 * Wraps one configured serve step for the pipeline: announces its stage,
 * resolves the inner step lazily (exactly like Pipeline::carry() would),
 * and advances the progress bar only after the step's own work succeeded —
 * the advance rides on the step calling $next. A step that throws never
 * advances; a step that returns without calling $next short-circuits the
 * remaining pipeline, leaving the bar partial.
 */
final class ReportedStep implements Step
{
    public function __construct(
        private readonly Container $container,
        private readonly StageReporter $reporter,
        private readonly StepDescriptor $planned,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        $this->reporter->starting($this->planned);

        /** @var Step $inner */
        $inner = $this->container->make($this->planned->class);

        return $inner->handle(
            $context,
            function (ServeContext $context) use ($next): mixed {
                $this->reporter->completed($this->planned);

                return $next($context);
            },
            ...$this->planned->parameters,
        );
    }
}
