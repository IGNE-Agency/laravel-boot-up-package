<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\ReservedPort;

test('a port with an env key is movable and says which key', function (): void {
    $port = new ReservedPort(port: 3306, purpose: 'mysql', envKey: 'FORWARD_DB_PORT');

    expect($port->isRemappable())->toBeTrue()
        ->and($port->remedy())->toBe('set FORWARD_DB_PORT in your .env');
});

test('a port that moves with a URL names both', function (): void {
    $port = new ReservedPort(port: 80, purpose: 'laravel.test', envKey: 'APP_PORT', urlKey: 'APP_URL');

    expect($port->isRemappable())->toBeTrue()
        ->and($port->remedy())->toBe('set APP_PORT in your .env (and APP_URL to match)');
});

test('a port with only a fix is not movable and reads that fix back', function (): void {
    $port = new ReservedPort(port: 5173, purpose: 'laravel.test', fix: 'set VITE_PORT in your .env');

    expect($port->isRemappable())->toBeFalse()
        ->and($port->remedy())->toBe('set VITE_PORT in your .env');
});

test('a port with neither falls back to naming what wants it', function (): void {
    $port = new ReservedPort(port: 8123, purpose: 'clickhouse');

    expect($port->isRemappable())->toBeFalse()
        ->and($port->remedy())->toBe('move the published port for clickhouse');
});

test('the search for a replacement starts at the next port up', function (): void {
    expect((new ReservedPort(port: 3306, purpose: 'mysql'))->searchFrom())->toBe(3307)
        ->and((new ReservedPort(port: 8000, purpose: 'serve'))->searchFrom())->toBe(8001);
});

test('a privileged port is moved clear of the reserved range instead', function (): void {
    // 81 is free far more often than it is useful; 8080 is where anyone
    // moving an HTTP port by hand would put it.
    expect((new ReservedPort(port: 80, purpose: 'laravel.test'))->searchFrom())->toBe(8080)
        ->and((new ReservedPort(port: 443, purpose: 'laravel.test'))->searchFrom())->toBe(8080)
        ->and((new ReservedPort(port: 1023, purpose: 'odd'))->searchFrom())->toBe(8080)
        ->and((new ReservedPort(port: 1024, purpose: 'odd'))->searchFrom())->toBe(1025);
});
