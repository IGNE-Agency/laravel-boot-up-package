<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Servers\ActiveServer;
use Igne\LaravelBootstrap\Servers\ActiveServerStore;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bootstrap-active-server-'.bin2hex(random_bytes(4));
    $this->path = $this->dir.'/active-server.json';
    $this->store = new ActiveServerStore($this->path);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

test('remember and current round-trip the record', function (): void {
    $this->store->remember(new ActiveServer(
        key: 'herd',
        startedByUs: true,
        servePid: 1234,
        startedAt: '2026-07-10T10:00:00+00:00',
    ));

    $current = $this->store->current();

    expect($current)->not->toBeNull()
        ->and($current->key)->toBe('herd')
        ->and($current->startedByUs)->toBeTrue()
        ->and($current->servePid)->toBe(1234)
        ->and($current->startedAt)->toBe('2026-07-10T10:00:00+00:00')
        ->and(is_file($this->path))->toBeTrue()
        ->and(is_file($this->path.'.tmp'))->toBeFalse();
});

test('remember overwrites the previous record', function (): void {
    $this->store->remember(new ActiveServer('herd', true, 1, '2026-07-10T10:00:00+00:00'));
    $this->store->remember(new ActiveServer('sail', false, 2, '2026-07-10T11:00:00+00:00'));

    expect($this->store->current()->key)->toBe('sail')
        ->and($this->store->current()->startedByUs)->toBeFalse();
});

test('current is null when no record was written', function (): void {
    expect($this->store->current())->toBeNull();
});

test('current is null for a corrupt file', function (): void {
    mkdir($this->dir, 0755, true);
    file_put_contents($this->path, '{not json');

    expect($this->store->current())->toBeNull();
});

test('current is null when the payload misses keys', function (): void {
    mkdir($this->dir, 0755, true);
    file_put_contents($this->path, json_encode(['key' => 'herd']));

    expect($this->store->current())->toBeNull();
});

test('clear removes the record and is a no-op when already gone', function (): void {
    $this->store->remember(new ActiveServer('laravel', true, 99, '2026-07-10T10:00:00+00:00'));

    $this->store->clear();

    expect(is_file($this->path))->toBeFalse()
        ->and($this->store->current())->toBeNull();

    $this->store->clear();

    expect($this->store->current())->toBeNull();
});
