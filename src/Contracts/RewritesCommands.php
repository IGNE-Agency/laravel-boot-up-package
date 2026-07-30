<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

use Igne\LaravelBootUp\Data\CommandRewrites;

/**
 * Capability: project commands must be rewritten to run inside the
 * server's environment (e.g. Sail prefixes everything with
 * ./vendor/bin/sail). Servers without this contract run commands as-is.
 */
interface RewritesCommands
{
    public function commandRewrites(): CommandRewrites;
}
