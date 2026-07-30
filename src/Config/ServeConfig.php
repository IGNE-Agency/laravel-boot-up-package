<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Config;

use Illuminate\Contracts\Config\Repository;

final readonly class ServeConfig
{
    /**
     * @param  list<string>  $steps  the app:serve pipeline; [] here because the
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
            steps: (array) $config->get('boot-up.serve.steps', []),
            openBrowser: (bool) $config->get('boot-up.serve.open_browser', true),
            autoAccept: (bool) $config->get('boot-up.serve.auto_accept', false),
        );
    }
}
