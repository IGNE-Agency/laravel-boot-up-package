<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Environment\Steps;

use Closure;
use Igne\LaravelBootstrap\Environment\EnvFile;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\Step;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

final class EnsureEnvFile implements Step
{
    public function __construct(private readonly EnvFile $envFile) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if ($this->envFile->exists()) {
            note('.env already exists.');

            return $next($context);
        }

        $this->envFile->createFromExample();
        info('.env created from .env.example.');

        return $next($context);
    }
}
