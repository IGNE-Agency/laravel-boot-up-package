<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Enums\RunMode;

test('fromConfig maps the documented values', function (): void {
    expect(RunMode::fromConfig('combined'))->toBe(RunMode::Combined)
        ->and(RunMode::fromConfig('terminal'))->toBe(RunMode::Terminal)
        ->and(RunMode::fromConfig('background'))->toBe(RunMode::Background);
});

test('unknown strings fall through to background', function (): void {
    expect(RunMode::fromConfig('sideways'))->toBe(RunMode::Background)
        ->and(RunMode::fromConfig(''))->toBe(RunMode::Background);
});
