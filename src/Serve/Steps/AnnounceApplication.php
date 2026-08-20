<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Label;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Config\ServeConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Serve\Browser;

#[Stage(ServeStage::Announce)]
#[Group('announce')]
#[Label('Announcing the application')]
final class AnnounceApplication implements Step
{
    public function __construct(
        private readonly ServeConfig $config,
        private readonly Browser $browser,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if ($context->server === null) {
            return $next($context);
        }

        $url = $context->server->url();

        terminal()->success("{$context->server->label()} is serving the application at {$url}");

        // Pointing at the log directory would mislead when the dev processes
        // are about to take over this terminal instead of writing log files.
        $context->options->follow
            ? terminal()->note('The dev processes start below — press q or Ctrl+C to stop everything.')
            : terminal()->note('Background process logs live in storage/logs/boot-up/.');

        terminal()->note('Stop everything with: php artisan app:down');

        if ($this->config->openBrowser) {
            $this->browser->open($url);
        }

        return $next($context);
    }
}
