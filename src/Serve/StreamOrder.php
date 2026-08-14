<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Serve;

use Igne\LaravelBootUp\Data\CombinedService;
use Igne\LaravelBootUp\Enums\StreamPosition;

/**
 * Orders the combined stream before it opens: canonical services keep
 * their familiar slots (a registration replacing a built-in inherits its
 * slot by name), registrations without a placement follow in registration
 * order, and first()/last()/before()/after() directives move them from
 * there. Safe to run on queued services — nothing has started yet.
 */
final readonly class StreamOrder
{
    public function __construct(private BootCommandRegistry $registry) {}

    public function apply(CombinedRunPlan $plan): CombinedRunPlan
    {
        $ordered = new CombinedRunPlan;

        foreach ($this->sort($plan->services()) as $service) {
            $ordered->add($service);
        }

        return $ordered;
    }

    /**
     * @param  list<CombinedService>  $services
     * @return list<CombinedService>
     */
    public function sort(array $services): array
    {
        $services = $this->baseOrder($services);
        $placed = array_filter(
            $this->registry->launchable(),
            fn (PendingBootProcess $process): bool => $process->placement() !== null,
        );

        $firsts = $this->extract($services, $this->names($placed, StreamPosition::First));
        $lasts = $this->extract($services, $this->names($placed, StreamPosition::Last));

        $services = [...$firsts, ...$services, ...$lasts];

        return $this->applyRelativePlacements($services, $placed);
    }

    /**
     * Canonical stream names first, in their usual slots; everything else
     * after them in insertion order. With nothing registered this is the
     * identity — the pipeline already queues canonically.
     *
     * @param  list<CombinedService>  $services
     * @return list<CombinedService>
     */
    private function baseOrder(array $services): array
    {
        $rank = array_flip([BootCommandRegistry::RESERVED_STREAM, ...BootCommandRegistry::BUILT_IN_STREAMS]);

        usort(
            $services,
            fn (CombinedService $a, CombinedService $b): int => ($rank[$a->name] ?? PHP_INT_MAX) <=> ($rank[$b->name] ?? PHP_INT_MAX),
        );

        return $services;
    }

    /**
     * Before/After moves, applied in registration order against the
     * concrete list. The per-target offset keeps several after('queue')
     * registrations in registration order instead of stacking in reverse;
     * an unknown or absent target leaves the service where it was.
     *
     * @param  list<CombinedService>  $services
     * @param  list<PendingBootProcess>  $placed
     * @return list<CombinedService>
     */
    private function applyRelativePlacements(array $services, array $placed): array
    {
        $afterOffsets = [];

        foreach ($placed as $process) {
            $placement = $process->placement();

            if ($placement !== StreamPosition::Before && $placement !== StreamPosition::After) {
                continue;
            }

            $index = $this->indexOf($services, $process->name());

            if ($index === null) {
                continue;
            }

            [$service] = array_splice($services, $index, 1);

            $target = (string) $process->placementTarget();
            $targetIndex = $this->indexOf($services, $target);

            if ($targetIndex === null) {
                array_splice($services, $index, 0, [$service]);

                continue;
            }

            $insertAt = $placement === StreamPosition::Before
                ? $targetIndex
                : $targetIndex + 1 + ($afterOffsets[$target] ?? 0);

            if ($placement === StreamPosition::After) {
                $afterOffsets[$target] = ($afterOffsets[$target] ?? 0) + 1;
            }

            array_splice($services, min($insertAt, \count($services)), 0, [$service]);
        }

        return $services;
    }

    /**
     * The stream names asking for this position, in registration order.
     *
     * @param  list<PendingBootProcess>  $placed
     * @return list<string>
     */
    private function names(array $placed, StreamPosition $position): array
    {
        return array_values(array_map(
            fn (PendingBootProcess $process): string => $process->name(),
            array_filter($placed, fn (PendingBootProcess $process): bool => $process->placement() === $position),
        ));
    }

    /**
     * Pull the named services out of the list, in the order given.
     *
     * @param  list<CombinedService>  $services
     * @param  list<string>  $names
     * @return list<CombinedService>
     */
    private function extract(array &$services, array $names): array
    {
        $extracted = [];

        foreach ($names as $name) {
            $index = $this->indexOf($services, $name);

            if ($index !== null) {
                [$extracted[]] = array_splice($services, $index, 1);
            }
        }

        return $extracted;
    }

    /**
     * @param  list<CombinedService>  $services
     */
    private function indexOf(array $services, string $name): ?int
    {
        foreach ($services as $index => $service) {
            if ($service->name === $name) {
                return $index;
            }
        }

        return null;
    }
}
