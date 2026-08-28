<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Boot\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Label;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Enums\BootStage;

/**
 * Says where the application is being served.
 *
 * Announcing is all it does. Opening the URL belongs to app:up, after the
 * pipeline: this step runs before `php artisan dev` has started the Vite
 * watcher or, under the artisan driver, `php artisan serve` — so a browser
 * opened from here reaches a URL that cannot yet render. A step could not
 * decide otherwise either, since which dev processes are going to run is only
 * known once the pipeline has returned.
 */
#[Stage(BootStage::Announce)]
#[Group('announce')]
#[Label('Announcing the application')]
final class AnnounceApplication implements Step
{
    public function handle(BootContext $context, Closure $next): mixed
    {
        if ($context->server === null) {
            return $next($context);
        }

        terminal()->success("{$context->server->label()} is serving the application at {$context->server->url()}");

        return $next($context);
    }
}
