<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Concerns\ValidatesConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Illuminate\Contracts\Config\Repository;

/**
 * Everything `php artisan dev` needs: the boot pipeline it works through,
 * how it asks before starting, and which processes run afterwards.
 */
final readonly class DevConfig
{
    use ValidatesConfig;

    /**
     * @param  list<string>  $steps  the boot pipeline; [] here because the
     *                               canonical list lives in the published config file
     */
    public function __construct(
        public array $steps = [],
        public bool $openBrowser = true,
        public bool $autoAccept = false,
        public bool $logs = true,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            steps: self::validatedSteps((array) $config->get('boot-up.dev.steps', []), 'boot-up.dev.steps', Step::class),
            openBrowser: (bool) $config->get('boot-up.dev.open_browser', true),
            autoAccept: (bool) $config->get('boot-up.dev.auto_accept', false),
            logs: (bool) $config->get('boot-up.dev.logs', true),
        );
    }
}
