<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Environment\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Label;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Enums\BootStage;
use Igne\LaravelBootUp\Environment\LocalEnvironment;
use Igne\LaravelBootUp\Exceptions\EnvironmentException;

#[Stage(BootStage::Prepare)]
#[Group('prepare')]
#[Label('Checking the local environment')]
final class EnsureLocalEnvironment implements Step
{
    public function __construct(
        private readonly LocalEnvironment $environment,
    ) {}

    public function handle(BootContext $context, Closure $next): mixed
    {
        if (! $this->environment->isAllowed()) {
            throw EnvironmentException::unsupportedEnvironment(
                $this->environment->name(),
                $this->environment->allowed(),
            );
        }

        if ($this->environment->isRemoteHost()) {
            throw EnvironmentException::remoteHost();
        }

        return $next($context);
    }
}
