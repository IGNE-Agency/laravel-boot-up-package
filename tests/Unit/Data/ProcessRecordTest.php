<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\ProcessRecord;

test('round-trips through the array form', function (): void {
    $record = new ProcessRecord(4242, 'queue', 'php artisan queue:work', date(DATE_ATOM));

    expect(ProcessRecord::fromArray($record->toArray()))->toEqual($record);
});

test('reads a ledger written before the record was simplified', function (): void {
    // Older entries carried a run mode and a terminal-window handle; both are
    // gone, and an entry that still has them must still load.
    $record = ProcessRecord::fromArray([
        'pid' => 4242,
        'label' => 'queue',
        'command' => 'php artisan queue:work',
        'started_at' => date(DATE_ATOM),
        'window' => '17',
        'mode' => 'combined',
    ]);

    expect($record->pid)->toBe(4242)
        ->and($record->label)->toBe('queue');
});

test('outputLocation points at the process log', function (): void {
    $record = new ProcessRecord(3, 'reverb', 'cmd', date(DATE_ATOM));

    expect($record->outputLocation())->toBe('logs: storage/logs/boot-up/reverb.log');
});
