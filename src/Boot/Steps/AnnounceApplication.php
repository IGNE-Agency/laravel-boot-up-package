<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Boot\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Label;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Boot\Browser;
use Igne\LaravelBootUp\Config\DevConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Enums\BootStage;
use Igne\LaravelBootUp\Process\ProcessRunner;

#[Stage(BootStage::Announce)]
#[Group('announce')]
#[Label('Announcing the application')]
final class AnnounceApplication implements Step
{
    public function __construct(
        private readonly DevConfig $config,
        private readonly Browser $browser,
    ) {}

    public function handle(BootContext $context, Closure $next): mixed
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
            : terminal()->note('Background process logs live in storage/'.ProcessRunner::LOG_SUBDIRECTORY.'/.');

        terminal()->note('Stop everything with: php artisan app:down');

        if ($this->config->openBrowser) {
            $this->browser->open($url);
        }

        return $next($context);
    }
}
