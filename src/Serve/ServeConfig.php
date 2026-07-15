<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Serve;

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
    ) {}

    public static function fromRepository(Repository $config): self
    {
        return new self(
            serveSteps: (array) $config->get('bootstrap.serve_steps', []),
            deploySteps: (array) $config->get('bootstrap.deploy_steps', []),
            openBrowser: (bool) $config->get('bootstrap.browser.open', true),
        );
    }
}
