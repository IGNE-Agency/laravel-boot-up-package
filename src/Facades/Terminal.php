<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Facades;

use Igne\LaravelBootUp\Support\TrackedProgress;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void intro(string $message)
 * @method static void outro(string $message)
 * @method static void success(string $message)
 * @method static void info(string $message)
 * @method static void note(string|array $message)
 * @method static void warning(string $message)
 * @method static void error(string $message)
 * @method static void blank()
 * @method static void heading(string $title)
 * @method static void section(string $title, array $lines = [], ?string $description = null)
 * @method static void list(array $items)
 * @method static void summary(string $title, array $items, ?string $footer = null)
 * @method static void table(array $headers, array $rows)
 * @method static bool confirm(string $label, bool $default = true, string $yes = 'Yes', string $no = 'No', bool|string $required = false, mixed $validate = null, string $hint = '')
 * @method static int|string select(string $label, array $options, int|string|null $default = null, int $scroll = 5, mixed $validate = null, string $hint = '', bool|string $required = true)
 * @method static string text(string $label, string $placeholder = '', string $default = '', bool|string $required = false, mixed $validate = null, string $hint = '')
 * @method static string password(string $label, string $placeholder = '', bool|string $required = false, mixed $validate = null, string $hint = '')
 * @method static TrackedProgress progress(string $label, iterable|int $steps, string $hint = '')
 *
 * @see \Igne\LaravelBootUp\Support\Terminal
 */
final class Terminal extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Igne\LaravelBootUp\Support\Terminal::class;
    }
}
