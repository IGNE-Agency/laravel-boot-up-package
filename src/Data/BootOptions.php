<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

/**
 * The flags one run was invoked with.
 *
 * Both commands build one: app:setup uses every field, `dev` only the two
 * that decide which processes to register, so the same gates answer the same
 * way whichever command asked.
 */
final readonly class BootOptions
{
    public function __construct(
        public bool $seed = false,
        public bool $migrate = true,
        public bool $update = false,
        public bool $withQueue = true,
        public bool $withAssets = true,
        public bool $fresh = false,
    ) {}
}
