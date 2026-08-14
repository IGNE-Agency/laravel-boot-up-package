<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Enums\StreamColor;

test('the palette carries the artisan dev hex values', function (): void {
    expect(array_column(StreamColor::cases(), 'value'))->toBe([
        '#93c5fd', // blue
        '#c4b5fd', // purple
        '#fb7185', // pink
        '#fdba74', // orange
        '#86efac', // green
        '#fcd34d', // yellow
    ]);
});
