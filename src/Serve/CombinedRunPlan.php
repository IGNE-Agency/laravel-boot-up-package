<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Igne\LaravelBootUp\Data\CombinedService;

/**
 * Collects the services queued for the combined output stream while the
 * boot steps run one by one; ServeCommand streams them together once the
 * pipeline completes. Mutable by design — the one stateful collector in
 * an otherwise readonly Data world.
 */
final class CombinedRunPlan
{
    /** @var list<CombinedService> */
    private array $services = [];

    public function add(CombinedService $service): void
    {
        $this->services[] = $service;
    }

    /**
     * @return list<CombinedService>
     */
    public function services(): array
    {
        return $this->services;
    }

    /**
     * Whether anything actually needs starting; tail-only plans (just the
     * detached serve log) never hold the stream open.
     */
    public function hasProcesses(): bool
    {
        return collect($this->services)->contains(fn (CombinedService $service): bool => $service->isProcess());
    }

    public function isEmpty(): bool
    {
        return $this->services === [];
    }
}
