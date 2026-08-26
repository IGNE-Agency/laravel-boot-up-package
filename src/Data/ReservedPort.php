<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

/**
 * A host port a server will bind when it starts.
 *
 * $envKey is set only when moving the port is HARMLESS -- a host-side
 * forward nothing else depends on. Ports whose value is also read by the
 * application (APP_PORT drives APP_URL, VITE_PORT is both ends of its own
 * mapping) carry a $fix sentence instead, so the guard explains rather than
 * silently rewrites something it cannot finish.
 */
final readonly class ReservedPort
{
    public function __construct(
        public int $port,
        public string $purpose,
        public ?string $envKey = null,
        public ?string $fix = null,
    ) {}

    public function isRemappable(): bool
    {
        return $this->envKey !== null;
    }

    /**
     * How the user frees this port themselves.
     */
    public function remedy(): string
    {
        if ($this->envKey !== null) {
            return "set {$this->envKey} in your .env";
        }

        return $this->fix ?? "move the published port for {$this->purpose}";
    }
}
