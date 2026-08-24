<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Concerns\ValidatesConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Illuminate\Contracts\Config\Repository;

/**
 * What `php artisan app:setup` does: the pipeline that gets a project ready
 * to run, and how it asks before doing it.
 */
final readonly class SetupConfig
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
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            steps: self::validatedSteps((array) $config->get('boot-up.setup.steps', []), 'boot-up.setup.steps', Step::class),
            openBrowser: (bool) $config->get('boot-up.setup.open_browser', true),
            autoAccept: (bool) $config->get('boot-up.setup.auto_accept', false),
        );
    }
}
