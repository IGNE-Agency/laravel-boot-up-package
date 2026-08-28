<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Services\PortProbe;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    Prompt::fake();
    $this->workDir = sys_get_temp_dir().'/boot-up-port-probe-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);
    $this->sockets = [];
});

afterEach(function (): void {
    foreach ($this->sockets as $socket) {
        fclose($socket);
    }

    exec('rm -rf '.escapeshellarg($this->workDir));
});

function portProbe(string $workDir): PortProbe
{
    return new PortProbe(new ProcessRunner(
        processes: app(Factory::class),
        ledger: new ProcessLedger($workDir.'/processes.json'),
        logDirectory: $workDir.'/logs',
    ));
}

/**
 * Bind a real listener the probe has to notice. Port 0 lets the OS pick one
 * that is genuinely free right now, so the test never races a real service.
 */
function occupyPort(object $test): int
{
    $socket = stream_socket_server('tcp://0.0.0.0:0', $errno, $error);

    expect($socket)->not->toBeFalse("could not bind a test port: {$error}");

    $test->sockets[] = $socket;
    $name = (string) stream_socket_get_name($socket, false);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

test('a bound port is unavailable and a free one is available', function (): void {
    ProcessFaker::fake();
    $taken = occupyPort($this);
    $probe = portProbe($this->workDir);

    expect($probe->isAvailable($taken))->toBeFalse();

    // Closing the only listener hands the port straight back: nothing ever
    // accepted on it, so there is no TIME_WAIT to wait out.
    fclose(array_pop($this->sockets));

    expect($probe->isAvailable($taken))->toBeTrue();
});

test('nextAvailable skips a taken port', function (): void {
    ProcessFaker::fake();
    $taken = occupyPort($this);

    expect(portProbe($this->workDir)->nextAvailable($taken))->toBeGreaterThan($taken);
});

test('nextAvailable gives up rather than inventing a port', function (): void {
    ProcessFaker::fake();

    // The search window starts past the last legal port, so every candidate
    // is out of range.
    expect(portProbe($this->workDir)->nextAvailable(65536))->toBeNull();
});

test('the holder is read from lsof machine-readable output', function (): void {
    ProcessFaker::fake([
        'sh -c *' => Process::result(output: '/usr/sbin/lsof'),
        'lsof *' => Process::result(output: "p7026\ncmysqld\nf19\n"),
    ]);

    expect(portProbe($this->workDir)->holderOf(3306))->toBe('mysqld (PID 7026)');

    ProcessFaker::assertRan('lsof -nP -iTCP:3306 -sTCP:LISTEN +c 0 -Fpc');
});

test('a port nobody holds reports no holder', function (): void {
    ProcessFaker::fake([
        'sh -c *' => Process::result(output: '/usr/sbin/lsof'),
        // lsof exits non-zero when no process matches the filter.
        'lsof *' => Process::result(exitCode: 1),
    ]);

    expect(portProbe($this->workDir)->holderOf(5173))->toBeNull();
});

test('a machine without lsof is never asked', function (): void {
    ProcessFaker::fake(['sh -c *' => Process::result(exitCode: 1)]);

    expect(portProbe($this->workDir)->holderOf(5173))->toBeNull();
    ProcessFaker::assertDidntRun('lsof *');
});

test('a holder lsof cannot name is not reported as an empty one', function (): void {
    ProcessFaker::fake([
        'sh -c *' => Process::result(output: '/usr/sbin/lsof'),
        'lsof *' => Process::result(output: "f19\n"),
    ]);

    expect(portProbe($this->workDir)->holderOf(3306))->toBeNull();
});
