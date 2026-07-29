<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve\Steps;

use Closure;
use Igne\LaravelBootUp\Config\ServeConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Serve\Browser;
use Igne\LaravelBootUp\Serve\CombinedRunPlan;

final class AnnounceApplication implements Step
{
    public function __construct(
        private readonly ServeConfig $config,
        private readonly Browser $browser,
        private readonly CombinedRunPlan $plan,
    ) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        if ($context->server === null) {
            return $next($context);
        }

        $url = $context->server->url();

        terminal()->success("{$context->server->label()} is serving the application at {$url}");

        // Pointing at the log directory would mislead when the workers are
        // about to stream right here instead of writing log files.
        $context->options->follow && $this->plan->hasProcesses()
            ? terminal()->note('Service output streams below — press Ctrl+C to stop everything.')
            : terminal()->note('Background process logs live in storage/logs/boot-up/.');

        terminal()->note('Stop everything with: php artisan app:down');

        if ($this->config->openBrowser) {
            $this->browser->open($url);
        }

        return $next($context);
    }
}
