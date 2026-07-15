<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve\Steps;

use Closure;
use Igne\LaravelBootUp\Serve\Browser;
use Igne\LaravelBootUp\Serve\ServeConfig;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

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

        info("{$context->server->label()} is serving the application at {$url}");
        note('Background process logs live in storage/logs/boot-up/.');
        note('Stop everything with: php artisan app:down');

        if ($this->config->openBrowser) {
            $this->browser->open($url);
        }

        return $next($context);
    }
}
