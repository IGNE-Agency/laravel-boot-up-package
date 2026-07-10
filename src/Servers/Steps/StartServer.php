<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Servers\Steps;

use Closure;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\Step;
use Igne\LaravelBootstrap\Servers\ActiveServer;
use Igne\LaravelBootstrap\Servers\ActiveServerStore;

use function Laravel\Prompts\info;

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

        info("{$server->label()} is running.");

        return $next($context);
    }
}
