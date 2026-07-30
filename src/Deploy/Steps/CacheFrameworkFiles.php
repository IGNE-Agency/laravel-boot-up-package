<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Deploy\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Concerns\SkipsWithNote;
use Igne\LaravelBootUp\Config\DeployConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Process\ProcessRunner;

/**
 * Off by default: config:cache freezes env() lookups, which breaks local
 * development against a mutating .env.
 */
#[Stage(ServeStage::Cache)]
#[Group('cache')]
final class CacheFrameworkFiles implements Step
{
    use SkipsWithNote;

    private const array COMMANDS = ['config:cache', 'route:cache', 'view:cache'];

    public function __construct(
        private readonly DeployConfig $config,
        private readonly ProcessRunner $processes,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if (! $this->config->cacheFrameworkFiles) {
            return $this->skipStep('Framework file caching is disabled in configuration — skipping.', $context, $next);
        }

        terminal()->info('Caching framework files...');

        foreach (self::COMMANDS as $command) {
            $this->processes->run(CommandLine::make(['php', 'artisan', $command]));
        }

        return $next($context);
    }
}
