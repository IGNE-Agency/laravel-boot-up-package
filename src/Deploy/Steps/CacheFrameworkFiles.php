<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Deploy\Steps;

use Closure;
use Igne\LaravelBootstrap\Deploy\DeployConfig;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\ShellCommand;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\Step;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

/**
 * Off by default: config:cache freezes env() lookups, which breaks local
 * development against a mutating .env.
 */
final class CacheFrameworkFiles implements Step
{
    private const COMMANDS = ['config:cache', 'route:cache', 'view:cache'];

    public function __construct(
        private readonly DeployConfig $config,
        private readonly ProcessRunner $processes,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if (! $this->config->cacheFrameworkFiles) {
            note('Framework file caching is disabled; skipping.');

            return $next($context);
        }

        info('Caching framework files...');

        foreach (self::COMMANDS as $command) {
            $this->processes->run(ShellCommand::make(['php', 'artisan', $command]));
        }

        return $next($context);
    }
}
