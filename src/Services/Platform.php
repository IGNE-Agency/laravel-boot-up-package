<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Services;

use Igne\LaravelBootUp\Enums\OperatingSystem;

/**
 * The operating-system family the package runs on — the ONLY place that
 * reads PHP_OS_FAMILY. Injected as a singleton so tests can simulate
 * another platform. The is*() methods stay the public surface; the enum
 * exists so a family is a value, not a magic string.
 */
final class Platform
{
    private readonly OperatingSystem $family;

    public function __construct(?OperatingSystem $family = null)
    {
        // An enum from() call is not a valid constant expression, so the
        // PHP_OS_FAMILY default lives here instead of the signature.
        $this->family = $family ?? OperatingSystem::from(PHP_OS_FAMILY);
    }

    public function isMacos(): bool
    {
        return $this->family === OperatingSystem::Darwin;
    }

    public function isLinux(): bool
    {
        return $this->family === OperatingSystem::Linux;
    }

    public function isWindows(): bool
    {
        return $this->family === OperatingSystem::Windows;
    }
}
