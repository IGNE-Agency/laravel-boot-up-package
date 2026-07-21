<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy\Steps;

use Closure;
use Igne\LaravelBootUp\Deploy\DeployConfig;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;

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
            terminal()->note('Framework file caching is disabled; skipping.');

            return $next($context);
        }

        terminal()->info('Caching framework files...');

        foreach (self::COMMANDS as $command) {
            $this->processes->run(ShellCommand::make(['php', 'artisan', $command]));
        }

        return $next($context);
    }
}
