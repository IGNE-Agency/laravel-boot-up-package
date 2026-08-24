<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Frontend\ViteHotFile;

beforeEach(function (): void {
    $this->path = sys_get_temp_dir().'/boot-up-hot-'.bin2hex(random_bytes(4));
});

afterEach(function (): void {
    is_file($this->path) && unlink($this->path);
});

test('exists sees a marker written after an earlier miss', function (): void {
    $hotFile = new ViteHotFile($this->path);

    // The whole point: the deferred browser asks in a loop, and PHP's stat
    // cache remembers misses, so a marker Vite writes mid-wait has to be seen.
    expect($hotFile->exists())->toBeFalse();

    file_put_contents($this->path, 'http://[::1]:5173');

    expect($hotFile->exists())->toBeTrue();
});

test('remove deletes the marker a killed watcher left behind', function (): void {
    file_put_contents($this->path, 'http://[::1]:5173');

    (new ViteHotFile($this->path))->remove();

    expect(is_file($this->path))->toBeFalse();
});

test('removing a marker that is not there is a no-op', function (): void {
    (new ViteHotFile($this->path))->remove();

    expect(is_file($this->path))->toBeFalse();
});

test('the path is the one it was given', function (): void {
    expect((new ViteHotFile('/somewhere/public/hot'))->path())->toBe('/somewhere/public/hot');
});
