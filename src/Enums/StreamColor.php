<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Enums;

/**
 * The palette for combined-stream prefixes — the same hex values Laravel's
 * own `php artisan dev` uses. Rendered through Terminal::hex(), which
 * degrades to 256/16 colours on terminals without truecolor support.
 */
enum StreamColor: string
{
    case Blue = '#93c5fd';
    case Purple = '#c4b5fd';
    case Pink = '#fb7185';
    case Orange = '#fdba74';
    case Green = '#86efac';
    case Yellow = '#fcd34d';
}
