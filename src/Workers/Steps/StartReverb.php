<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Workers\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Config\ReverbConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\WorkerDefinition;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Serve\WorkerLauncher;

/**
 * Starts a tracked Reverb WebSocket server when laravel/reverb is a
 * project dependency. Detect-and-skip, like Horizon.
 */
#[Stage(ServeStage::Services)]
#[Group('workers')]
final class StartReverb implements Step
{
    private const string LABEL = 'reverb';

    public function __construct(
        private readonly ReverbConfig $config,
        private readonly ComposerJson $composerJson,
        private readonly WorkerLauncher $launcher,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if ($this->config->enabled && $this->composerJson->requires('laravel/reverb')) {
            $this->launcher->launch($this->worker(), $context);
        }

        return $next($context);
    }

    private function worker(): WorkerDefinition
    {
        return new WorkerDefinition(
            label: self::LABEL,
            name: 'Reverb',
            tokens: ['php', 'artisan', 'reverb:start'],
            runIn: $this->config->runIn,
        );
    }
}
