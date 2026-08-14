<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Enums\StreamColor;
use Igne\LaravelBootUp\Services\Terminal;

test('hex wraps text in a foreground colour and resets it', function (): void {
    $colored = (new Terminal)->hex('#93c5fd', 'queue');

    // The exact escape depends on the terminal's colour depth (truecolor,
    // 256, or 16) — the invariants are a foreground set, the text, a reset.
    expect($colored)->toMatch('/^\e\[[\d;]+mqueue\e\[39m$/');
});

test('orange renders through the stream palette', function (): void {
    $terminal = new Terminal;

    expect($terminal->orange('careful'))->toBe($terminal->hex(StreamColor::Orange->value, 'careful'));
});
