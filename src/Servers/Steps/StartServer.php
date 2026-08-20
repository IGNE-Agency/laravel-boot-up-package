<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Label;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Enums\ServeStage;
use Igne\LaravelBootUp\Servers\ActiveServerStore;

/**
 * Boots the selected server. The active-server record is written BEFORE
 * start() runs (write-ahead) so a crash mid-start still leaves shutdown
 * enough state to clean up.
 */
#[Stage(ServeStage::Server)]
#[Group('server')]
#[Label('Starting the development server')]
final class StartServer implements Step
{
    public function __construct(private readonly ActiveServerStore $store) {}

    public function handle(ServeContext $context, Closure $next): mixed
    {
        $server = $context->server;

        if ($server === null) {
            return $next($context);
        }

        $wasRunning = $server->isRunning();
        $context->serverWasAlreadyRunning = $wasRunning;

        $this->store->remember(new ActiveServerRecord(
            key: $server->key(),
            startedByUs: ! $wasRunning,
            servePid: (int) getmypid(),
            startedAt: date(DATE_ATOM),
        ));

        $server->start($context);

        terminal()->success("{$server->label()} is running.");

        return $next($context);
    }
}
