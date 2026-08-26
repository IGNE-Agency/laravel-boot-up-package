<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Contracts;

use Igne\LaravelBootUp\Data\ReservedPort;

/**
 * A server that binds host ports when it starts, and can say which ones
 * before it tries. Implement it and the boot probes them first, so a clash
 * is a sentence instead of half-created Docker resources and a raw daemon
 * error.
 *
 * Return [] whenever the answer cannot be determined cheaply: an unknown
 * port list means "do not check", never "nothing to check".
 */
interface ReservesPorts
{
    /**
     * @return list<ReservedPort>
     */
    public function reservedPorts(): array;
}
