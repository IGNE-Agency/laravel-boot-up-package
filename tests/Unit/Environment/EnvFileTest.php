<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Environment\EnvironmentException;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-envfile-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    $this->envPath = $this->dir.'/.env';
    $this->examplePath = $this->dir.'/.env.example';
    $this->envFile = new EnvFile($this->envPath, $this->examplePath);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

test('exists reflects the presence of the env file', function (): void {
    expect($this->envFile->exists())->toBeFalse();

    file_put_contents($this->envPath, "APP_ENV=local\n");

    expect($this->envFile->exists())->toBeTrue();
});

test('createFromExample copies the example content verbatim', function (): void {
    file_put_contents($this->examplePath, "APP_NAME=Laravel\nAPP_ENV=local\n");

    $this->envFile->createFromExample();

    expect($this->envFile->exists())->toBeTrue()
        ->and(file_get_contents($this->envPath))->toBe("APP_NAME=Laravel\nAPP_ENV=local\n");
});

test('createFromExample throws when no example file exists', function (): void {
    $this->envFile->createFromExample();
})->throws(EnvironmentException::class, '.env.example');

test('get returns plain values and null for absent keys', function (): void {
    file_put_contents($this->envPath, "APP_ENV=local\nAPP_DEBUG=true\n");

    expect($this->envFile->get('APP_ENV'))->toBe('local')
        ->and($this->envFile->get('APP_DEBUG'))->toBe('true')
        ->and($this->envFile->get('MISSING'))->toBeNull();
});

test('get returns null when the env file itself is missing', function (): void {
    expect($this->envFile->get('APP_ENV'))->toBeNull();
});

test('get unquotes double- and single-quoted values', function (): void {
    file_put_contents($this->envPath, implode("\n", [
        'APP_NAME="My App"',
        "MAIL_FROM='hello world'",
        'ESCAPED="say \\"hi\\""',
        'EMPTY_QUOTED=""',
    ])."\n");

    expect($this->envFile->get('APP_NAME'))->toBe('My App')
        ->and($this->envFile->get('MAIL_FROM'))->toBe('hello world')
        ->and($this->envFile->get('ESCAPED'))->toBe('say "hi"')
        ->and($this->envFile->get('EMPTY_QUOTED'))->toBe('');
});

test('get returns an empty string for a key without a value', function (): void {
    file_put_contents($this->envPath, "APP_KEY=\n");

    expect($this->envFile->get('APP_KEY'))->toBe('');
});

test('get does not match keys that only share a prefix', function (): void {
    file_put_contents($this->envPath, "DB_HOST_READ=replica\n");

    expect($this->envFile->get('DB_HOST'))->toBeNull();
});

test('has is true for present keys even when the value is empty', function (): void {
    file_put_contents($this->envPath, "APP_KEY=\nAPP_ENV=local\n");

    expect($this->envFile->has('APP_KEY'))->toBeTrue()
        ->and($this->envFile->has('APP_ENV'))->toBeTrue()
        ->and($this->envFile->has('MISSING'))->toBeFalse();
});

test('set replaces an existing line while preserving comments, order and other lines', function (): void {
    file_put_contents($this->envPath, implode("\n", [
        '# Application settings',
        'APP_NAME=Laravel',
        'APP_ENV=local',
        '',
        '# Database',
        'DB_HOST=127.0.0.1',
    ])."\n");

    $this->envFile->set('APP_ENV', 'development');

    expect(file_get_contents($this->envPath))->toBe(implode("\n", [
        '# Application settings',
        'APP_NAME=Laravel',
        'APP_ENV=development',
        '',
        '# Database',
        'DB_HOST=127.0.0.1',
    ])."\n");
});

test('set appends a new line when the key is absent', function (): void {
    file_put_contents($this->envPath, "APP_ENV=local\n");

    $this->envFile->set('DB_DATABASE', 'app');

    expect(file_get_contents($this->envPath))->toBe("APP_ENV=local\nDB_DATABASE=app\n");
});

test('set appends on its own line even when the file lacks a trailing newline', function (): void {
    file_put_contents($this->envPath, 'APP_ENV=local');

    $this->envFile->set('DB_DATABASE', 'app');

    expect(file_get_contents($this->envPath))->toBe("APP_ENV=local\nDB_DATABASE=app\n");
});

test('set quotes values containing spaces, hashes or quotes and get round-trips them', function (): void {
    file_put_contents($this->envPath, "APP_ENV=local\n");

    $this->envFile->set('APP_NAME', 'My App');
    $this->envFile->set('PASSWORD', 'p#ss');
    $this->envFile->set('QUOTED', 'say "hi"');

    $content = (string) file_get_contents($this->envPath);

    expect($content)->toContain('APP_NAME="My App"')
        ->and($content)->toContain('PASSWORD="p#ss"')
        ->and($content)->toContain('QUOTED="say \\"hi\\""')
        ->and($this->envFile->get('APP_NAME'))->toBe('My App')
        ->and($this->envFile->get('PASSWORD'))->toBe('p#ss')
        ->and($this->envFile->get('QUOTED'))->toBe('say "hi"');
});

test('set writes values containing dollar signs and backslashes literally', function (): void {
    file_put_contents($this->envPath, "DB_PASSWORD=old\n");

    $this->envFile->set('DB_PASSWORD', 'pa$1ss\\0');

    expect($this->envFile->get('DB_PASSWORD'))->toBe('pa$1ss\\0');
});

test('set replaces the same key repeatedly without duplicating lines', function (): void {
    file_put_contents($this->envPath, "APP_ENV=local\n");

    $this->envFile->set('APP_ENV', 'development');
    $this->envFile->set('APP_ENV', 'local');

    expect(substr_count((string) file_get_contents($this->envPath), 'APP_ENV='))->toBe(1)
        ->and($this->envFile->get('APP_ENV'))->toBe('local');
});

test('set throws when the env file does not exist', function (): void {
    $this->envFile->set('APP_ENV', 'local');
})->throws(EnvironmentException::class, '.env file does not exist');

test('setMany applies every pair in one write', function (): void {
    file_put_contents($this->envPath, "APP_ENV=local\nDB_HOST=old\n");

    $this->envFile->setMany([
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'app',
    ]);

    expect($this->envFile->get('DB_HOST'))->toBe('127.0.0.1')
        ->and($this->envFile->get('DB_PORT'))->toBe('3306')
        ->and($this->envFile->get('DB_DATABASE'))->toBe('app')
        ->and($this->envFile->get('APP_ENV'))->toBe('local');
});

test('missing reports keys that are absent or have an empty value', function (): void {
    file_put_contents($this->envPath, "DB_HOST=127.0.0.1\nDB_PASSWORD=\nDB_USERNAME=\"\"\n");

    expect($this->envFile->missing(['DB_HOST', 'DB_PASSWORD', 'DB_USERNAME', 'DB_DATABASE']))
        ->toBe(['DB_PASSWORD', 'DB_USERNAME', 'DB_DATABASE']);
});

test('writes are atomic and leave no temporary files behind', function (): void {
    file_put_contents($this->envPath, "APP_ENV=local\n");

    $this->envFile->set('APP_ENV', 'development');
    $this->envFile->setMany(['DB_HOST' => '127.0.0.1']);

    $files = array_values(array_diff(scandir($this->dir) ?: [], ['.', '..']));

    expect($files)->toBe(['.env']);
});
