<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Support;

/**
 * The operating-system family the package runs on — the ONLY place that
 * reads PHP_OS_FAMILY. Injected as a singleton so tests can simulate
 * another platform.
 */
final class Platform
{
    public function __construct(private readonly string $family = PHP_OS_FAMILY) {}

    public function isMacos(): bool
    {
        return $this->family === 'Darwin';
    }

    public function isLinux(): bool
    {
        return $this->family === 'Linux';
    }

    public function isWindows(): bool
    {
        return $this->family === 'Windows';
    }
}
