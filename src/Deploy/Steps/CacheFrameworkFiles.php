<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy\Steps;

use Closure;
use Igne\LaravelBootUp\Config\DeployConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ShellCommand;
use Igne\LaravelBootUp\Process\ProcessRunner;

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
            terminal()->note('Framework file caching is disabled in configuration — skipping.');

            return $next($context);
        }

        terminal()->info('Caching framework files...');

        foreach (self::COMMANDS as $command) {
            $this->processes->run(ShellCommand::make(['php', 'artisan', $command]));
        }

        return $next($context);
    }
}
