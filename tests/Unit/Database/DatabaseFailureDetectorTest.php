<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Database\DatabaseFailureDetector;
use Igne\LaravelBootUp\Enums\DatabaseFailure;

it('classifies real driver failures', function (string $output, DatabaseFailure $expected): void {
    expect((new DatabaseFailureDetector)->classify($output))->toBe($expected);
})->with([
    'mysql rejected credentials' => [
        "SQLSTATE[HY000] [1045] Access denied for user 'sail'@'192.168.65.1' (using password: YES)",
        DatabaseFailure::AccessDenied,
    ],
    // Captured from a real Sail container: this, not 1049, is what MySQL
    // tells an ordinary user about a database its volume never created.
    'mysql denied for a database that is not there' => [
        "SQLSTATE[HY000] [1044] Access denied for user 'sail'@'%' to database 'not-a-database'",
        DatabaseFailure::UnknownDatabase,
    ],
    'pgsql rejected credentials' => [
        'SQLSTATE[08006] [7] connection to server failed: FATAL:  password authentication failed for user "sail"',
        DatabaseFailure::AccessDenied,
    ],
    'sqlsrv rejected credentials' => [
        "SQLSTATE[28000]: [18456] Login failed for user 'sail'.",
        DatabaseFailure::AccessDenied,
    ],
    'mysql unknown database' => [
        "SQLSTATE[HY000] [1049] Unknown database 'igne-dashboard'",
        DatabaseFailure::UnknownDatabase,
    ],
    'pgsql unknown database' => [
        'SQLSTATE[08006] [7] connection to server failed: FATAL:  database "igne-dashboard" does not exist',
        DatabaseFailure::UnknownDatabase,
    ],
    'sqlsrv unknown database' => [
        'SQLSTATE[42000]: [4060] Cannot open database "igne-dashboard" requested by the login.',
        DatabaseFailure::UnknownDatabase,
    ],
    'sqlite unopenable file' => [
        'SQLSTATE[HY000] [14] unable to open database file',
        DatabaseFailure::UnknownDatabase,
    ],
    'container not accepting connections yet' => [
        'SQLSTATE[HY000] [2002] Connection refused',
        DatabaseFailure::Transient,
    ],
    'service name not resolving yet' => [
        'SQLSTATE[HY000] [2002] php_network_getaddrinfo: getaddrinfo for mysql failed',
        DatabaseFailure::Transient,
    ],
    'postgres still initializing' => [
        'SQLSTATE[57P03] the database system is starting up',
        DatabaseFailure::Transient,
    ],
    'compose has not started the service' => [
        'service "laravel.test" is not running',
        DatabaseFailure::Transient,
    ],
]);

it('never reads a missing table as a missing database', function (string $output): void {
    // These come back from a connection that worked, so they must not stop
    // the poll with a verdict about the database itself.
    expect((new DatabaseFailureDetector)->classify($output))->toBe(DatabaseFailure::Transient);
})->with([
    'pgsql missing relation' => ['SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "users" does not exist'],
    'mysql missing table' => ["SQLSTATE[42S02]: Base table or view not found: 1146 Table 'igne.users' doesn't exist"],
    'no migrations table' => ['ERROR  Migration table not found.'],
]);

it('answers whether waiting is pointless', function (): void {
    $detector = new DatabaseFailureDetector;

    expect($detector->isTerminal("SQLSTATE[HY000] [1045] Access denied for user 'sail'"))->toBeTrue()
        ->and($detector->isTerminal("SQLSTATE[HY000] [1049] Unknown database 'igne'"))->toBeTrue()
        ->and($detector->isTerminal('SQLSTATE[HY000] [2002] Connection refused'))->toBeFalse();
});
