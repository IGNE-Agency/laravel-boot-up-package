<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Deploy\Composer;
use Igne\LaravelBootUp\Enums\ServeStage;

#[Stage(ServeStage::Install)]
#[Group('dependencies')]
final class InstallComposerDependencies implements Step
{
    public function __construct(private readonly Composer $composer) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        $this->composer->install($context->options->update);

        return $next($context);
    }
}
