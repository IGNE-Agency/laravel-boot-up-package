<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

/**
 * Where a registered process asks to appear in the combined output stream,
 * relative to the canonical order. Before/After carry a target stream name
 * alongside them.
 */
enum StreamPosition
{
    case First;
    case Last;
    case Before;
    case After;
}
