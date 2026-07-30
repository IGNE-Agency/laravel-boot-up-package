<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Services\AtomicFile;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-atomic-'.bin2hex(random_bytes(4));
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

test('write creates missing directories and the file', function (): void {
    $path = $this->dir.'/nested/deeper/state.json';

    AtomicFile::write($path, '{"ok":true}');

    expect(file_get_contents($path))->toBe('{"ok":true}');
});

test('write replaces existing content and leaves no temporary files behind', function (): void {
    $path = $this->dir.'/state.json';

    AtomicFile::write($path, 'first');
    AtomicFile::write($path, 'second');

    expect(file_get_contents($path))->toBe('second')
        ->and(glob($this->dir.'/state.json.tmp-*'))->toBeEmpty();
});

test('delete removes the file and tolerates a missing one', function (): void {
    $path = $this->dir.'/state.json';

    AtomicFile::write($path, 'content');
    AtomicFile::delete($path);

    expect(is_file($path))->toBeFalse();

    AtomicFile::delete($path);
});
