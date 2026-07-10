<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Process\ProcessLedger;
use Igne\LaravelBootstrap\Process\ProcessRecord;

beforeEach(function (): void {
    $this->path = sys_get_temp_dir().'/bootstrap-ledger-test-'.bin2hex(random_bytes(4)).'/processes.json';
    $this->ledger = new ProcessLedger($this->path);
});

afterEach(function (): void {
    if (is_file($this->path)) {
        unlink($this->path);
    }

    if (is_dir(dirname($this->path))) {
        rmdir(dirname($this->path));
    }
});

function record(int $pid = 100, string $label = 'queue-worker'): ProcessRecord
{
    return new ProcessRecord($pid, $label, 'php artisan queue:work', date(DATE_ATOM));
}

test('records persist across ledger instances', function (): void {
    $this->ledger->record(record(pid: 123));

    $fresh = new ProcessLedger($this->path);

    expect($fresh->all())->toHaveCount(1)
        ->and($fresh->all()->first()->pid)->toBe(123);
});

test('recording the same pid twice keeps one entry', function (): void {
    $this->ledger->record(record(pid: 1));
    $this->ledger->record(record(pid: 1));

    expect($this->ledger->all())->toHaveCount(1);
});

test('withLabel filters and forget removes by pid', function (): void {
    $this->ledger->record(record(pid: 1, label: 'queue-worker'));
    $this->ledger->record(record(pid: 2, label: 'assets-watch'));

    expect($this->ledger->withLabel('queue-worker'))->toHaveCount(1);

    $this->ledger->forget(1);

    expect($this->ledger->withLabel('queue-worker'))->toBeEmpty()
        ->and($this->ledger->all())->toHaveCount(1);
});

test('a corrupt ledger file reads as empty', function (): void {
    mkdir(dirname($this->path), 0755, true);
    file_put_contents($this->path, '{not json');

    expect($this->ledger->all())->toBeEmpty()
        ->and($this->ledger->isEmpty())->toBeTrue();
});

test('clear removes the file entirely', function (): void {
    $this->ledger->record(record());
    $this->ledger->clear();

    expect(is_file($this->path))->toBeFalse();
});
