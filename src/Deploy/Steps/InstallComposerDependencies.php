<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy\Steps;

use Closure;
use Igne\LaravelBootUp\Deploy\Composer;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;

final class InstallComposerDependencies implements Step
{
    public function __construct(private readonly Composer $composer) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        $this->composer->install($context->options->update);

        return $next($context);
    }
}
