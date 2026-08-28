<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Servers;

use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Services\JsonStore;

/**
 * Persists the active-server record as atomic JSON in
 * storage/framework/boot-up, so the three commands that need it agree:
 * app:up writes it, `dev` and app:status read it, app:down clears it.
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

        if (! ActiveServerRecord::hydratable($decoded)) {
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
