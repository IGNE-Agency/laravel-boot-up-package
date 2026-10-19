<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

use Igne\LaravelBootUp\Data\BootContext;

/**
 * Capability: the server has configuration to materialize, or decisions to
 * take, before its first start — scaffolding a compose file, choosing the
 * credentials a fresh database volume will be created with. StartServer
 * calls it before the port guard runs, so pre-flight checks probe the real
 * configuration instead of standing down on a missing one.
 *
 * Must be idempotent: start() calls it again for callers that skip the
 * pipeline. Only called for a server that is not already running.
 */
interface PreparesToStart
{
    public function prepare(BootContext $context): void;
}
