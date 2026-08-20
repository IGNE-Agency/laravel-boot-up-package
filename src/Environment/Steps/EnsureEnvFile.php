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
use Igne\LaravelBootUp\Environment\EnvFile;

#[Stage(BootStage::Prepare)]
#[Group('prepare')]
#[Label('Checking the .env file')]
final class EnsureEnvFile implements Step
{
    public function __construct(private readonly EnvFile $envFile) {}

    public function handle(BootContext $context, Closure $next): mixed
    {
        if ($this->envFile->exists()) {
            terminal()->note('.env already exists.');

            return $next($context);
        }

        $this->envFile->createFromExample();
        terminal()->success('.env created from .env.example.');

        return $next($context);
    }
}
