<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers\Steps;

use Closure;
use Igne\LaravelBootUp\Attributes\Group;
use Igne\LaravelBootUp\Attributes\Label;
use Igne\LaravelBootUp\Attributes\Stage;
use Igne\LaravelBootUp\Contracts\Step;
use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Enums\BootStage;
use Igne\LaravelBootUp\Servers\ActiveServerStore;

/**
 * Boots the selected server. The active-server record is written BEFORE
 * start() runs (write-ahead) so a crash mid-start still leaves shutdown
 * enough state to clean up.
 */
#[Stage(BootStage::Server)]
#[Group('server')]
#[Label('Starting the development server')]
final class StartServer implements Step
{
    public function __construct(private readonly ActiveServerStore $store) {}

    public function handle(BootContext $context, Closure $next): mixed
    {
        $server = $context->server;

        if ($server === null) {
            return $next($context);
        }

        // Whether we started it is what teardown needs, and the persisted
        // record is where it has to survive to -- app:down runs in another
        // process entirely.
        $wasRunning = $server->isRunning();

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
