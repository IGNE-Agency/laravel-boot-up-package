<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Steps;

use Closure;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\Step;
use Igne\LaravelBootUp\Servers\ActiveServer;
use Igne\LaravelBootUp\Servers\ActiveServerStore;

/**
 * Boots the selected server. The active-server record is written BEFORE
 * start() runs (write-ahead) so a crash mid-start still leaves shutdown
 * enough state to clean up.
 */
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

        $this->store->remember(new ActiveServer(
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
