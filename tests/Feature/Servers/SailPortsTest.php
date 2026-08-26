<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\Sail\Sail;
use Igne\LaravelBootUp\Servers\Sail\SailPorts;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    Prompt::fake();
    $this->workDir = sys_get_temp_dir().'/boot-up-sail-ports-'.bin2hex(random_bytes(4));
    $this->basePath = $this->workDir.'/app';
    mkdir($this->basePath, 0755, true);
    $this->app->setBasePath($this->basePath);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

function sailPorts(string $workDir): SailPorts
{
    $runner = new ProcessRunner(
        processes: app(Factory::class),
        ledger: new ProcessLedger($workDir.'/processes.json'),
        logDirectory: $workDir.'/logs',
    );

    return new SailPorts($runner, new Sail($runner));
}

/**
 * The shape `docker compose config --format json` really emits, trimmed to
 * the ports: published is a string, target is the container side.
 */
function composeConfig(array $services): string
{
    return (string) json_encode(['name' => 'app', 'services' => $services]);
}

function composePorts(array ...$ports): array
{
    return ['ports' => array_map(static fn (array $port): array => [
        'mode' => 'ingress',
        'protocol' => 'tcp',
        ...$port,
    ], $ports)];
}

test('reads the published ports a real Sail project resolves to', function (): void {
    touch($this->basePath.'/compose.yaml');
    ProcessFaker::fake([
        './vendor/bin/sail config --format json' => Process::result(output: composeConfig([
            'laravel.test' => composePorts(
                ['target' => 80, 'published' => '80'],
                ['target' => 5173, 'published' => '5173'],
            ),
            'mysql' => composePorts(['target' => 3306, 'published' => '3306']),
        ])),
    ]);

    $ports = sailPorts($this->workDir)->published();

    expect($ports)->toHaveCount(3)
        // The app's own ports are reported but not offered as movable: their
        // variables are read on both sides of the mapping.
        ->and($ports[0]->port)->toBe(80)
        ->and($ports[0]->purpose)->toBe('laravel.test')
        ->and($ports[0]->isRemappable())->toBeFalse()
        ->and($ports[0]->remedy())->toContain('APP_PORT')
        ->and($ports[0]->remedy())->toContain('APP_URL')
        ->and($ports[1]->remedy())->toContain('VITE_PORT')
        ->and($ports[1]->isRemappable())->toBeFalse()
        // The database forward is host-side only, so it can move.
        ->and($ports[2]->port)->toBe(3306)
        ->and($ports[2]->envKey)->toBe('FORWARD_DB_PORT')
        ->and($ports[2]->isRemappable())->toBeTrue()
        ->and($ports[2]->remedy())->toBe('set FORWARD_DB_PORT in your .env');
});

test('sail is asked with its own pre-checks skipped', function (): void {
    touch($this->basePath.'/compose.yaml');
    ProcessFaker::fake([
        './vendor/bin/sail config --format json' => Process::result(output: composeConfig([])),
    ]);

    sailPorts($this->workDir)->published();

    // Without SAIL_SKIP_CHECKS the wrapper runs `compose down` on exited
    // containers — a read must not tear anything down.
    Process::assertRan(fn ($process): bool => ($process->environment['SAIL_SKIP_CHECKS'] ?? null) === '1');
});

test('every service keeps its own forward variable', function (): void {
    touch($this->basePath.'/compose.yaml');
    ProcessFaker::fake([
        './vendor/bin/sail config --format json' => Process::result(output: composeConfig([
            'pgsql' => composePorts(['target' => 5432, 'published' => '5432']),
            'valkey' => composePorts(['target' => 6379, 'published' => '6379']),
            'mailpit' => composePorts(
                ['target' => 1025, 'published' => '1025'],
                ['target' => 8025, 'published' => '8025'],
            ),
            // minio and rustfs both publish 9000 behind different variables,
            // so the container port alone cannot name the right one.
            'rustfs' => composePorts(['target' => 9000, 'published' => '9010']),
        ])),
    ]);

    $keys = array_map(
        static fn (object $port): array => [$port->port, $port->envKey],
        sailPorts($this->workDir)->published(),
    );

    expect($keys)->toBe([
        [5432, 'FORWARD_DB_PORT'],
        [6379, 'FORWARD_VALKEY_PORT'],
        [1025, 'FORWARD_MAILPIT_PORT'],
        [8025, 'FORWARD_MAILPIT_DASHBOARD_PORT'],
        [9010, 'FORWARD_RUSTFS_PORT'],
    ]);
});

test('a service this package has never heard of is still reported', function (): void {
    touch($this->basePath.'/compose.yaml');
    ProcessFaker::fake([
        './vendor/bin/sail config --format json' => Process::result(output: composeConfig([
            'clickhouse' => composePorts(['target' => 8123, 'published' => '8123']),
        ])),
    ]);

    $ports = sailPorts($this->workDir)->published();

    expect($ports)->toHaveCount(1)
        ->and($ports[0]->port)->toBe(8123)
        ->and($ports[0]->isRemappable())->toBeFalse()
        ->and($ports[0]->remedy())->toBe('publish a different host port for clickhouse in your compose file');
});

test('ports a bind probe cannot reason about are left out', function (): void {
    touch($this->basePath.'/compose.yaml');
    ProcessFaker::fake([
        './vendor/bin/sail config --format json' => Process::result(output: composeConfig([
            'app' => composePorts(
                ['target' => 80, 'published' => ''],                      // not published to the host
                ['target' => 8000, 'published' => '8000-8005'],           // a range
                ['target' => 53, 'published' => '53', 'protocol' => 'udp'],
                ['target' => 3306, 'published' => '3306'],
            ),
            // A duplicate host port compose would refuse anyway; the report
            // must not repeat it.
            'mysql' => composePorts(['target' => 3306, 'published' => '3306']),
        ])),
    ]);

    $ports = sailPorts($this->workDir)->published();

    expect($ports)->toHaveCount(1)
        ->and($ports[0]->port)->toBe(3306)
        ->and($ports[0]->purpose)->toBe('app');
});

test('an unreadable config means do not check, never nothing to check', function (): void {
    touch($this->basePath.'/compose.yaml');

    ProcessFaker::fake(['./vendor/bin/sail config *' => Process::result(exitCode: 1)]);
    expect(sailPorts($this->workDir)->published())->toBe([]);

    ProcessFaker::fake(['./vendor/bin/sail config *' => Process::result(output: 'Docker is not running.')]);
    expect(sailPorts($this->workDir)->published())->toBe([]);

    ProcessFaker::fake(['./vendor/bin/sail config *' => Process::result(output: '{"name":"app"}')]);
    expect(sailPorts($this->workDir)->published())->toBe([]);
});

test('a project with no compose file is never asked', function (): void {
    ProcessFaker::fake();

    expect(sailPorts($this->workDir)->published())->toBe([]);

    ProcessFaker::assertDidntRun('./vendor/bin/sail config *');
});
