<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

/**
 * Capability: stopping this server reaches beyond the current project
 * (e.g. `herd stop` halts every Herd site on the machine). The impact is
 * shown and never acted on without an explicit confirmation. Servers
 * without this contract stop project-scoped.
 */
interface WarnsBeforeStop
{
    public function stopImpact(): string;
}
