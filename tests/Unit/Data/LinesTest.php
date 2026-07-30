<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\Lines;

test('an empty builder renders an empty string with no lines', function (): void {
    $lines = Lines::make();

    expect($lines->render())->toBe('')
        ->and($lines->toArray())->toBe([]);
});

test('a single line renders with exactly one trailing newline', function (): void {
    expect(Lines::make()->line('APP_ENV=testing')->render())->toBe("APP_ENV=testing\n");
});

test('a line with embedded newlines splits into physical lines', function (): void {
    $lines = Lines::make()->indent(2, fn (Lines $lines) => $lines->line("first\nsecond"));

    expect($lines->toArray())->toBe(['  first', '  second']);
});

test('blank is a separator: never first, never doubled, never trailing', function (): void {
    $lines = Lines::make()
        ->blank()
        ->line('a')
        ->blank()
        ->blank()
        ->line('b')
        ->blank();

    expect($lines->toArray())->toBe(['a', '', 'b'])
        ->and($lines->render())->toBe("a\n\nb\n");
});

test('blank after a literal empty line does not double up', function (): void {
    $lines = Lines::make()->line('a')->line('')->blank()->line('b');

    expect($lines->toArray())->toBe(['a', '', 'b']);
});

test('a literal empty line is preserved as content', function (): void {
    expect(Lines::make()->line('a')->line('')->line('b')->render())->toBe("a\n\nb\n");
});

test('comment renders each argument with a hash prefix', function (): void {
    $lines = Lines::make()->comment('First', 'Second')->comment('');

    expect($lines->toArray())->toBe(['# First', '# Second', '#']);
});

test('comments indent like any other line', function (): void {
    $lines = Lines::make()->indent(4, fn (Lines $lines) => $lines->comment('nested'));

    expect($lines->toArray())->toBe(['    # nested']);
});

test('lineIf appends only when the condition holds', function (): void {
    $lines = Lines::make()
        ->lineIf(true, 'kept')
        ->lineIf(false, 'dropped');

    expect($lines->toArray())->toBe(['kept']);
});

test('lineWithBreak is a blank separator plus a line', function (): void {
    $lines = Lines::make()
        ->lineWithBreak('first')
        ->line('second')
        ->lineWithBreak('third');

    expect($lines->toArray())->toBe(['first', 'second', '', 'third'])
        ->and(Lines::make()->lineWithBreak('alone')->toArray())->toBe(['alone']);
});

test('lineWithBreakIf appends the line-with-break only when the condition holds', function (): void {
    $lines = Lines::make()
        ->line('a')
        ->lineWithBreakIf(false, 'dropped')
        ->lineWithBreakIf(true, 'kept');

    expect($lines->toArray())->toBe(['a', '', 'kept']);
});

test('linesWithBreak separates a block and an empty block leaves the separator pending', function (): void {
    $lines = Lines::make()
        ->line('a')
        ->linesWithBreak(['b', 'c']);

    expect($lines->toArray())->toBe(['a', '', 'b', 'c'])
        ->and(Lines::make()->line('a')->linesWithBreak([])->line('d')->toArray())->toBe(['a', '', 'd']);
});

test('when runs the callback only for a true condition', function (): void {
    $lines = Lines::make()
        ->when(true, fn (Lines $lines) => $lines->line('yes'))
        ->when(false, fn (Lines $lines) => $lines->line('never'));

    expect($lines->toArray())->toBe(['yes']);
});

test('when falls back to the otherwise callback', function (): void {
    $lines = Lines::make()->when(
        false,
        fn (Lines $lines) => $lines->line('never'),
        fn (Lines $lines) => $lines->line('fallback'),
    );

    expect($lines->toArray())->toBe(['fallback']);
});

test('each passes the builder, value and key in iteration order', function (): void {
    $lines = Lines::make()->each(
        ['develop' => 'development', 'main' => 'production'],
        fn (Lines $lines, string $environment, string $branch) => $lines->line("{$branch} -> {$environment}"),
    );

    expect($lines->toArray())->toBe(['develop -> development', 'main -> production']);
});

test('indent prefixes content lines but never blank lines', function (): void {
    $lines = Lines::make()->indent(2, fn (Lines $lines) => $lines
        ->line('a')
        ->blank()
        ->line('b')
        ->line(''));

    expect($lines->toArray())->toBe(['  a', '', '  b', '']);
});

test('nested indents accumulate and restore afterwards', function (): void {
    $lines = Lines::make()
        ->line('root')
        ->indent(2, fn (Lines $lines) => $lines
            ->line('child')
            ->indent(4, fn (Lines $lines) => $lines->line('grandchild'))
            ->line('child again'))
        ->line('root again');

    expect($lines->toArray())->toBe([
        'root',
        '  child',
        '      grandchild',
        '  child again',
        'root again',
    ]);
});

test('indent restores the level even when the callback throws', function (): void {
    $lines = Lines::make();

    try {
        $lines->indent(2, function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    expect($lines->line('after')->toArray())->toBe(['after']);
});

test('lines appends an iterable at the current indent', function (): void {
    $lines = Lines::make()->indent(2, fn (Lines $lines) => $lines->lines(['a', 'b']));

    expect($lines->toArray())->toBe(['  a', '  b']);
});

test('lines re-indents another builder under the current indent and drops its pending blank', function (): void {
    $child = Lines::make()
        ->indent(2, fn (Lines $lines) => $lines->line('child'))
        ->blank();

    $lines = Lines::make()->indent(2, fn (Lines $lines) => $lines->lines($child));

    expect($lines->toArray())->toBe(['    child']);
});

test('toArray spreads alongside plain string lists', function (): void {
    $built = Lines::make()->line('b')->line('c');

    expect(['a', ...$built->toArray(), 'd'])->toBe(['a', 'b', 'c', 'd']);
});

test('heading renders like a comment in plain text but carries its own kind', function (): void {
    $lines = Lines::make()->heading('─── Build ───')->heading('');

    expect($lines->toArray())->toBe(['# ─── Build ───', '#'])
        ->and($lines->toStyledArray(fn (string $kind): string => $kind))
        ->toBe([Lines::KIND_HEADING, Lines::KIND_HEADING]);
});

test('commentIf appends a comment-kind line only when the condition holds', function (): void {
    $lines = Lines::make()->commentIf(true, 'kept')->commentIf(false, 'dropped');

    expect($lines->toArray())->toBe(['# kept'])
        ->and($lines->toStyledArray(fn (string $kind): string => $kind))->toBe([Lines::KIND_COMMENT]);
});

test('warning renders a "#!" shell comment tagged as a warning kind', function (): void {
    $lines = Lines::make()->warning('watch out', '')->warning('and again');

    expect($lines->toArray())->toBe(['#! watch out', '#!', '#! and again'])
        ->and($lines->toStyledArray(fn (string $kind): string => $kind))
        ->toBe([Lines::KIND_WARNING, Lines::KIND_WARNING, Lines::KIND_WARNING]);
});

test('toStyledArray maps each line through the styler by kind and passes blanks through', function (): void {
    $lines = Lines::make()
        ->comment('provenance')
        ->heading('─── Build ───')
        ->line('composer install')
        ->blank()
        ->line('php artisan migrate');

    expect($lines->toStyledArray(fn (string $kind, string $text): string => "{$kind}|{$text}"))->toBe([
        Lines::KIND_COMMENT.'|# provenance',
        Lines::KIND_HEADING.'|# ─── Build ───',
        Lines::KIND_COMMAND.'|composer install',
        '',
        Lines::KIND_COMMAND.'|php artisan migrate',
    ]);
});

test('lines(self) carries the source kinds so composed comments keep their styling', function (): void {
    $child = Lines::make()->comment('desc')->line('php artisan foo');

    $parent = Lines::make()->heading('─── Post ───')->lines($child);

    expect($parent->toStyledArray(fn (string $kind, string $text): string => "{$kind}:{$text}"))->toBe([
        Lines::KIND_HEADING.':# ─── Post ───',
        Lines::KIND_COMMENT.':# desc',
        Lines::KIND_COMMAND.':php artisan foo',
    ]);
});

test('lines(iterable) of plain strings are all command kind', function (): void {
    $lines = Lines::make()->lines(['a', 'b']);

    expect($lines->toStyledArray(fn (string $kind): string => $kind))->toBe([Lines::KIND_COMMAND, Lines::KIND_COMMAND]);
});

test('an injected separator blank keeps the kind array aligned with the lines', function (): void {
    $lines = Lines::make()->line('a')->lineWithBreak('b');

    // A misaligned kinds array would mislabel 'b' or error on the missing index.
    expect($lines->toStyledArray(fn (string $kind, string $text): string => $kind))->toBe([
        Lines::KIND_COMMAND,
        '',
        Lines::KIND_COMMAND,
    ]);
});
