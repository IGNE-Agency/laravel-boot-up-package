<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Contracts\DescribesProgress;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Deploy\Composer;
use Igne\LaravelBootUp\Enums\BootStage;

#[Stage(BootStage::Install)]
#[Group('dependencies')]
final class InstallComposerDependencies implements DescribesProgress, Step
{
    public function __construct(private readonly Composer $composer) {}

    public function handle(BootContext $context, Closure $next): mixed
    {
        $this->composer->install($context->options->update);

        return $next($context);
    }

    public static function progressLabel(BootOptions $options, array $parameters): string
    {
        return $options->update ? 'Updating Composer dependencies' : 'Installing Composer dependencies';
    }
}
