<?php

declare(strict_types=1);

namespace Igne\LaravelBootUp\Facades;

use Closure;
use Igne\LaravelBootUp\Services\TrackedProgress;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void intro(string $message)
 * @method static void outro(string $message)
 * @method static void success(string $message)
 * @method static void info(string $message)
 * @method static void note(string|list<string> $message)
 * @method static void warning(string $message)
 * @method static void error(string $message)
 * @method static void blank()
 * @method static void heading(string $title)
 * @method static string hex(string $hex, string $text)
 * @method static string orange(string $text)
 * @method static void section(string $title, list<string> $lines = [], ?string $description = null)
 * @method static void list(list<string> $items)
 * @method static void summary(string $title, list<string> $items, ?string $footer = null)
 * @method static void orderedList(string $title, list<string> $items)
 * @method static void table(list<string> $headers, list<list<string>> $rows)
 * @method static bool confirm(string $label, bool $default = true, string $yes = 'Yes', string $no = 'No', bool|string $required = false, mixed $validate = null, string $hint = '')
 * @method static int|string select(string $label, array<int|string, string> $options, int|string|null $default = null, int $scroll = 5, mixed $validate = null, string $hint = '', bool|string $required = true)
 * @method static string text(string $label, string $placeholder = '', string $default = '', bool|string $required = false, mixed $validate = null, string $hint = '')
 * @method static string password(string $label, string $placeholder = '', bool|string $required = false, mixed $validate = null, string $hint = '')
 * @method static TrackedProgress progress(string $label, iterable<mixed>|int $steps, string $hint = '')
 * @method static mixed suspend(Closure $callback)
 *
 * @see \Igne\LaravelBootUp\Services\Terminal
 */
final class Terminal extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Igne\LaravelBootUp\Services\Terminal::class;
    }
}
