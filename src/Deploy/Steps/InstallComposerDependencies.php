<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Deploy\Steps;

use Closure;
use Igne\LaravelBootstrap\Deploy\Composer;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\Step;

final class InstallComposerDependencies implements Step
{
    public function __construct(private readonly Composer $composer) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        $this->composer->install($context->options->update);

        return $next($context);
    }
}
