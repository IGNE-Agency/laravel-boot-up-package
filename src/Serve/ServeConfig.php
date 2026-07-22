<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Illuminate\Contracts\Config\Repository;

final readonly class ServeConfig
{
    /**
     * @param  list<string>  $serveSteps
     * @param  list<string>  $deploySteps
     */
    public function __construct(
        public array $serveSteps,
        public array $deploySteps,
        public bool $openBrowser,
        public bool $autoAccept,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            serveSteps: (array) $config->get('boot-up.serve_steps', []),
            deploySteps: (array) $config->get('boot-up.deploy_steps', []),
            openBrowser: (bool) $config->get('boot-up.browser.open', true),
            autoAccept: (bool) $config->get('boot-up.auto_accept', false),
        );
    }
}
