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

test('a directory it creates is born ignored by git', function (): void {
    // Laravel's storage/framework/.gitignore lists filenames rather than a
    // wildcard, so a new subdirectory of it is not covered by anything.
    $path = $this->dir.'/nested/state.json';

    AtomicFile::write($path, '{}');

    // The directory holding the file is the one that has to be covered; an
    // intermediate level can only ever contain this ignored one.
    expect(file_get_contents($this->dir.'/nested/.gitignore'))->toBe("*\n!.gitignore\n");
});

test('a directory that already exists is left as the project arranged it', function (): void {
    mkdir($this->dir, 0755, true);

    AtomicFile::write($this->dir.'/state.json', '{}');

    expect(is_file($this->dir.'/.gitignore'))->toBeFalse();
});

test('permissions are applied before the file is in place, never after', function (): void {
    $path = $this->dir.'/secret.json';

    AtomicFile::write($path, '{"password":"hunter2"}', 0600);

    expect(fileperms($path) & 0777)->toBe(0600);

    // Nothing may observe it at the umask default in between, so the mode has
    // to be set on the temporary file rather than on the final one.
    AtomicFile::write($path, '{"password":"hunter3"}', 0600);

    expect(fileperms($path) & 0777)->toBe(0600)
        ->and(glob($this->dir.'/secret.json.tmp-*'))->toBeEmpty();
});

test('without permissions the umask decides, as before', function (): void {
    $path = $this->dir.'/state.json';

    AtomicFile::write($path, '{}');

    expect(fileperms($path) & 0777)->toBe(0666 & ~umask());
});

test('delete removes the file and tolerates a missing one', function (): void {
    $path = $this->dir.'/state.json';

    AtomicFile::write($path, 'content');
    AtomicFile::delete($path);

    expect(is_file($path))->toBeFalse();

    AtomicFile::delete($path);
});
