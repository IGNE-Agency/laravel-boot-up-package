<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Database\DatabaseCreator;
use Igne\LaravelBootstrap\Database\DatabaseException;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bootstrap-db-creator-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    $this->creator = new DatabaseCreator;

    $this->sqlite = fn (string $path): array => [
        'driver' => 'sqlite',
        'database' => $path,
    ];
});

afterEach(function (): void {
    if (is_dir($this->dir)) {
        exec('rm -rf '.escapeshellarg($this->dir));
    }
});

test('a sqlite database exists when the file exists', function (): void {
    $path = $this->dir.'/database.sqlite';

    expect($this->creator->databaseExists(($this->sqlite)($path)))->toBeFalse();

    touch($path);

    expect($this->creator->databaseExists(($this->sqlite)($path)))->toBeTrue();
});

test('a sqlite :memory: database always exists', function (): void {
    expect($this->creator->databaseExists(($this->sqlite)(':memory:')))->toBeTrue();
});

test('createDatabase touches the sqlite file, creating parent directories', function (): void {
    $path = $this->dir.'/nested/deeper/database.sqlite';

    $this->creator->createDatabase(($this->sqlite)($path));

    expect(is_file($path))->toBeTrue();
});

test('createDatabase leaves an existing sqlite file untouched', function (): void {
    $path = $this->dir.'/database.sqlite';
    file_put_contents($path, 'precious data');

    $this->creator->createDatabase(($this->sqlite)($path));

    expect(file_get_contents($path))->toBe('precious data');
});

test('createDatabaseIfMissing reports whether it created the sqlite database', function (): void {
    $path = $this->dir.'/database.sqlite';

    expect($this->creator->createDatabaseIfMissing(($this->sqlite)($path)))->toBeTrue()
        ->and(is_file($path))->toBeTrue()
        ->and($this->creator->createDatabaseIfMissing(($this->sqlite)($path)))->toBeFalse();
});

test('an unreachable mysql server throws instead of being swallowed', function (): void {
    // Port 1 refuses instantly; a missing pdo_mysql extension throws just as
    // fast — either way the old return-false bug must not resurface.
    $this->creator->databaseExists([
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '1',
        'database' => 'igne',
        'username' => 'root',
        'password' => '',
    ]);
})->throws(DatabaseException::class, 'mysql');

test('an unsupported driver throws', function (): void {
    $this->creator->databaseExists(['driver' => 'mongodb', 'database' => 'igne']);
})->throws(DatabaseException::class, 'mongodb');
