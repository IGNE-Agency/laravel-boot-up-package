<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Igne\LaravelBootUp\Concerns\ValidatesConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Illuminate\Contracts\Config\Repository;

/**
 * What `php artisan app:up` does: the pipeline that gets a project ready
 * to run, and how it asks before doing it.
 */
final readonly class SetupConfig
{
    use ValidatesConfig;

    /**
     * @param  list<string>  $steps  the boot pipeline; [] here because the
     *                               canonical list lives in the published config file
     * @param  int  $browserWaitTimeoutSeconds  how long to wait for the application to
     *                                          be able to render before opening it anyway
     */
    public function __construct(
        public array $steps = [],
        public bool $openBrowser = true,
        public bool $autoAccept = false,
        public int $browserWaitTimeoutSeconds = 60,
        public int $browserPollIntervalMs = 500,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            steps: self::validatedSteps((array) $config->get('boot-up.setup.steps', []), 'boot-up.setup.steps', Step::class),
            openBrowser: (bool) $config->get('boot-up.setup.open_browser', true),
            autoAccept: (bool) $config->get('boot-up.setup.auto_accept', false),
            // Zero is allowed and means "do not wait": one check, then open.
            browserWaitTimeoutSeconds: self::atLeast($config->get('boot-up.setup.browser.wait_timeout_seconds', 60), 0, 'boot-up.setup.browser.wait_timeout_seconds'),
            // Unlike Herd's health delay, this one has a floor: the hot-file
            // check is a bare stat(), so a zero interval is a busy loop.
            browserPollIntervalMs: self::atLeast($config->get('boot-up.setup.browser.poll_interval_ms', 500), 50, 'boot-up.setup.browser.poll_interval_ms'),
        );
    }
}
