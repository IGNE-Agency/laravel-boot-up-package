<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

/**
 * Where a boot command registration came from, resolved from the call
 * site's backtrace. Application code always outranks vendor packages, so a
 * package can suggest a process but never silently override the app's.
 */
enum RegistrationSource: int
{
    case Vendor = 1;
    case Application = 2;

    /**
     * A new registration replaces an existing same-name one only when it
     * ranks at least as high — later registrations from the same source
     * win, vendor never displaces application code.
     */
    public function mayReplace(self $existing): bool
    {
        return $this->value >= $existing->value;
    }
}
