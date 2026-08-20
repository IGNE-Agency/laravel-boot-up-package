<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

final readonly class BootOptions
{
    /**
     * @param  bool  $follow  stream combined worker output in this terminal
     *                        after the boot; false detaches everything
     *                        (--detach, or stdout is not an interactive
     *                        terminal)
     */
    public function __construct(
        public bool $seed = false,
        public bool $migrate = true,
        public bool $update = false,
        public bool $withQueue = true,
        public bool $withAssets = true,
        public bool $fresh = false,
        public bool $follow = true,
    ) {}
}
