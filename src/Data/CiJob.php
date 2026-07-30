<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Data;

/**
 * One CI status-check job (lint/build/test) as the pipeline generators
 * render it. GitHub uses the description and timeout; Bitbucket uses the
 * node-cache flag — one record spares both a parameter parade.
 */
final readonly class CiJob
{
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public int $timeoutMinutes,
        public bool $usesNode,
    ) {}
}
