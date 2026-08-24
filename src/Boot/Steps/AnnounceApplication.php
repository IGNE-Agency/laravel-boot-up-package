<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Boot\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Label;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Boot\Browser;
use Igne\LaravelBootUp\Config\SetupConfig;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Enums\BootStage;

#[Stage(BootStage::Announce)]
#[Group('announce')]
#[Label('Announcing the application')]
final class AnnounceApplication implements Step
{
    public function __construct(
        private readonly SetupConfig $config,
        private readonly Browser $browser,
    ) {}

    public function handle(BootContext $context, Closure $next): mixed
    {
        if ($context->server === null) {
            return $next($context);
        }

        $url = $context->server->url();

        terminal()->success("{$context->server->label()} is serving the application at {$url}");

        terminal()->note('Run `php artisan dev` to start the dev processes.');
        terminal()->note('Stop the server with: php artisan app:down');

        if ($this->config->openBrowser) {
            $this->browser->open($url);
        }

        return $next($context);
    }
}
