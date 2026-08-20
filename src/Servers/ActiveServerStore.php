<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Services\JsonStore;

/**
 * Persists the active-server record across the dev / app:down
 * boundary as atomic JSON in storage/framework/boot-up.
 */
final class ActiveServerStore
{
    private readonly JsonStore $store;

    public function __construct(string $path)
    {
        $this->store = new JsonStore(
            $path,
            'The boot-up active-server record was corrupt — moved to %s and reset. A previously started server may still be running.',
        );
    }

    public function remember(ActiveServerRecord $server): void
    {
        $this->store->write($server->toArray());
    }

    public function current(): ?ActiveServerRecord
    {
        $decoded = $this->store->read();

        if ($decoded === null) {
            return null;
        }

        if (! isset($decoded['key'], $decoded['started_by_us'], $decoded['serve_pid'], $decoded['started_at'])) {
            $this->store->quarantine();

            return null;
        }

        return ActiveServerRecord::fromArray($decoded);
    }

    public function clear(): void
    {
        $this->store->clear();
    }
}
