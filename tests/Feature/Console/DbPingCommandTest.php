<?php

declare(strict_types=1);

test('a reachable database exits successfully', function (): void {
    config()->set('database.connections.ping', ['driver' => 'sqlite', 'database' => ':memory:']);
    config()->set('database.default', 'ping');

    $this->artisan('app:db-ping')
        ->expectsOutputToContain('Database connection established [ping].')
        ->assertSuccessful();
});

test('an unreachable database exits with the driver message for the detector to read', function (): void {
    config()->set('database.connections.ping', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '1',
        'database' => 'igne',
        'username' => 'sail',
        'password' => 'password',
    ]);
    config()->set('database.default', 'ping');

    // Plain, undecorated output: VerifyDatabaseConnection classifies this
    // text to decide between waiting longer and giving up.
    $this->artisan('app:db-ping')
        ->expectsOutputToContain('SQLSTATE')
        ->assertFailed();
});

test('it stays out of the command list', function (): void {
    expect(Illuminate\Support\Facades\Artisan::all()['app:db-ping']->isHidden())->toBeTrue();
});
