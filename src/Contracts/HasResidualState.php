<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

/**
 * Capability: a failed or aborted boot of this server can leave residual
 * state behind even when the server reports not-running (e.g. Sail's
 * stopped containers, networks and half-pulled images). Shutdown offers
 * a cleanup instead of silently skipping the server. Servers without
 * this contract are skipped silently when not running.
 */
interface HasResidualState
{
    public function hasResidualState(): bool;

    /** One line: what may be left behind and what cleanup runs. */
    public function residualStateImpact(): string;

    /** Clean up. Must only clean — never install, never prompt. */
    public function cleanUpResidualState(): void;
}
