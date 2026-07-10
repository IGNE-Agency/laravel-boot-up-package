<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Environment\Steps;

use Closure;
use Igne\LaravelBootstrap\Environment\EnvFile;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\ShellCommand;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\Step;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

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
            note('Application key already set.');

            return $next($context);
        }

        // Always host-side (no server rewriting): the key must land in the
        // host .env file that every later step reads.
        $this->processes->run(ShellCommand::make('php artisan key:generate --ansi'));
        info('Application key generated.');

        return $next($context);
    }
}
