<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Enums\RunMode;

test('mode round-trips through the array form', function (): void {
    $record = new ProcessRecord(4242, 'queue-worker', 'php artisan queue:work', date(DATE_ATOM), mode: RunMode::Combined);

    expect(ProcessRecord::fromArray($record->toArray())->mode)->toBe(RunMode::Combined);
});

test('outputLocation distinguishes combined, terminal-window and background processes', function (): void {
    $combined = new ProcessRecord(1, 'queue-worker', 'cmd', date(DATE_ATOM), mode: RunMode::Combined);
    $windowed = new ProcessRecord(2, 'horizon', 'cmd', date(DATE_ATOM), window: '17');
    $background = new ProcessRecord(3, 'reverb', 'cmd', date(DATE_ATOM));

    expect($combined->outputLocation())->toBe('output streams in the app:serve terminal')
        ->and($windowed->outputLocation())->toBe('output is in its terminal window')
        ->and($background->outputLocation())->toBe('logs: storage/logs/boot-up/reverb.log');
});
