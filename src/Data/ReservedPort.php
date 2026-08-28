<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

/**
 * A host port a server will bind when it starts.
 *
 * $envKey is set only when the move can be COMPLETED -- a host-side forward
 * nothing else depends on, or a port whose every other appearance this object
 * names. $urlKey is that second case: APP_PORT moves only if APP_URL's port
 * moves with it. A port with neither (VITE_PORT is both ends of its own
 * mapping, PUSHER_PORT is fixed inside the container) carries a $fix sentence
 * instead, so the guard explains rather than half-rewriting something it
 * cannot finish.
 */
final readonly class ReservedPort
{
    /** Below this, a port's neighbours are other services' reserved ports. */
    private const int PRIVILEGED_CEILING = 1024;

    /** Where a port moved off a privileged one starts looking instead. */
    private const int UNPRIVILEGED_START = 8080;

    public function __construct(
        public int $port,
        public string $purpose,
        public ?string $envKey = null,
        public ?string $fix = null,
        public ?string $urlKey = null,
    ) {}

    public function isRemappable(): bool
    {
        return $this->envKey !== null;
    }

    /**
     * Where to start looking for a port to move to.
     *
     * The next one up is the obvious answer for 3306 → 3307, and a poor one
     * for 80 → 81: a privileged port's neighbours are other services'
     * reserved numbers, and 8080 is where anyone moving an HTTP port by hand
     * would go.
     */
    public function searchFrom(): int
    {
        return $this->port < self::PRIVILEGED_CEILING
            ? self::UNPRIVILEGED_START
            : $this->port + 1;
    }

    /**
     * How the user frees this port themselves.
     */
    public function remedy(): string
    {
        if ($this->envKey === null) {
            return $this->fix ?? "move the published port for {$this->purpose}";
        }

        return $this->urlKey === null
            ? "set {$this->envKey} in your .env"
            : "set {$this->envKey} in your .env (and {$this->urlKey} to match)";
    }
}
