<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Environment\Steps;

use Closure;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;

final class EnsureEnvFile implements Step
{
    public function __construct(private readonly EnvFile $envFile) {}

    public function handle(ServeContext $context, Closure $next): mixed
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
