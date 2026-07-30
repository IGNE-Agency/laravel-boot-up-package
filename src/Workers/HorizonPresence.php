<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Workers;

use Igne\LaravelBootUp\Config\HorizonConfig;
use Igne\LaravelBootUp\Pipelines\ComposerJson;

/**
 * Whether Horizon is the project's queue runner — enabled in config AND
 * actually required by composer.json. Both the Horizon worker (to start)
 * and the plain queue worker (to stand down) ask this one question.
 */
final class HorizonPresence
{
    public function __construct(
        private readonly HorizonConfig $config,
        private readonly ComposerJson $composerJson,
    ) {}

    public function managesQueue(): bool
    {
        return $this->config->enabled && $this->composerJson->requires('laravel/horizon');
    }
}
