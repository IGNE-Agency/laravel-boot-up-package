<?php

declare(strict_types=1);

namespace Igne\LaravelBootstrap\Serve;

final readonly class ServeOptions
{
    public function __construct(
        public bool $seed = false,
        public bool $migrate = true,
        public bool $update = false,
        public bool $withQueue = true,
        public bool $withAssets = true,
    ) {}
}
