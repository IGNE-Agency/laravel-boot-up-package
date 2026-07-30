<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

/**
 * The complete PHP_OS_FAMILY value set, so Platform can be constructed from
 * it with a strict from() — an unexpected family is a bug, not a fallback.
 */
enum OperatingSystem: string
{
    case Windows = 'Windows';
    case Bsd = 'BSD';
    case Darwin = 'Darwin';
    case Solaris = 'Solaris';
    case Linux = 'Linux';
    case Unknown = 'Unknown';
}
