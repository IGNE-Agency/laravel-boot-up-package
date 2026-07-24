<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Environment\Steps;

use Closure;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Process\ProcessRunner;

final class GenerateAppKey implements Step
{
    public function __construct(
        private readonly EnvFile $envFile,
        private readonly ProcessRunner $processes,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        $key = $this->envFile->get('APP_KEY');

        if ($key !== null && $key !== '') {
            terminal()->note('Application key already set.');

            return $next($context);
        }

        // Always host-side (no server rewriting): the key must land in the
        // host .env file that every later step reads.
        $this->processes->run(CommandLine::make('php artisan key:generate --ansi'));
        terminal()->success('Application key generated.');

        return $next($context);
    }
}
