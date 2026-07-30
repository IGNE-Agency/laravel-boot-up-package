<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Contracts\TerminalLauncher;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Process\NullTerminalLauncher;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Services\Poller;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;

/**
 * A terminal launcher that opens a window (returns a fixed handle) but never
 * writes the pid file, so the runner always exercises its timeout path.
 */
function fakeTerminal(): TerminalLauncher
{
    return new class implements TerminalLauncher
    {
        public array $closed = [];

        public function available(): bool
        {
            return true;
        }

        public function open(string $command, ?string $directory = null): ?string
        {
            return '42';
        }

        public function close(?string $handle): void
        {
            $this->closed[] = $handle;
        }
    };
}

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/boot-up-runner-test-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);
    $this->ledger = new ProcessLedger($this->workDir.'/processes.json');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

function makeRunner(ProcessLedger $ledger, string $workDir): ProcessRunner
{
    return new ProcessRunner(
        processes: app(Factory::class),
        ledger: $ledger,
        terminal: new NullTerminalLauncher,
        poller: new Poller,
        logDirectory: $workDir.'/logs',
        runtimeDirectory: $workDir.'/runtime',
    );
}

test('run throws on failure', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'boom')]);

    makeRunner($this->ledger, $this->workDir)->run(CommandLine::make('composer install'));
})->throws(ProcessFailedException::class);

test('runSilently never throws on failure and returns the result', function (): void {
    Process::fake(['*' => Process::result(exitCode: 127)]);

    $result = makeRunner($this->ledger, $this->workDir)->runSilently(CommandLine::make('command -v missing'));

    expect($result->successful())->toBeFalse()->and($result->exitCode())->toBe(127);
});

test('start spawns a detached nohup wrapper and records the pid in the ledger', function (): void {
    Process::fake(['*' => Process::result(output: "4242\n")]);

    $record = makeRunner($this->ledger, $this->workDir)
        ->start(CommandLine::make('php artisan serve'), 'artisan-serve');

    Process::assertRan(function ($process): bool {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return str_contains($command, 'nohup php artisan serve')
            && str_contains($command, 'artisan-serve.log')
            && str_contains($command, 'echo $!');
    });

    expect($record->pid)->toBe(4242)
        ->and($record->label)->toBe('artisan-serve')
        ->and($this->ledger->withLabel('artisan-serve'))->toHaveCount(1);
});

test('start throws when no pid is echoed back', function (): void {
    Process::fake(['*' => Process::result(output: '')]);

    makeRunner($this->ledger, $this->workDir)->start(CommandLine::make('php artisan serve'), 'artisan-serve');
})->throws(Igne\LaravelBootUp\Exceptions\ProcessException::class);

test('startInTerminal degrades to a tracked background start when no terminal exists', function (): void {
    Process::fake(['*' => Process::result(output: "77\n")]);

    $record = makeRunner($this->ledger, $this->workDir)
        ->startInTerminal(CommandLine::make('bun run dev'), 'assets-watch');

    expect($record->pid)->toBe(77)
        ->and($this->ledger->withLabel('assets-watch'))->toHaveCount(1);
});

test('startInTerminal recovers the PID from the process table when the pid file is not written in time', function (): void {
    // The only OS process the recover path runs is the pgrep probe.
    Process::fake(['*' => Process::result(output: "9999\n")]);

    $runner = new ProcessRunner(
        processes: app(Factory::class),
        ledger: $this->ledger,
        terminal: fakeTerminal(),
        poller: new Poller,
        logDirectory: $this->workDir.'/logs',
        runtimeDirectory: $this->workDir.'/runtime',
        terminalPidTimeout: 0,
    );

    $record = $runner->startInTerminal(CommandLine::make('php artisan queue:work database'), 'queue-worker');

    expect($record->pid)->toBe(9999)
        ->and($record->window)->toBe('42')
        ->and($this->ledger->withLabel('queue-worker'))->toHaveCount(1);

    Process::assertRan(fn ($process): bool => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : $process->command,
        'pgrep -fn',
    ));
});

test('startInTerminal closes the window and falls back to background when no PID can be recovered', function (): void {
    // First OS call = the pgrep probe (no match); second = the nohup start().
    Process::fake([
        '*' => Process::sequence()
            ->push(Process::result(exitCode: 1))
            ->push(Process::result(output: "555\n")),
    ]);

    $terminal = fakeTerminal();

    $runner = new ProcessRunner(
        processes: app(Factory::class),
        ledger: $this->ledger,
        terminal: $terminal,
        poller: new Poller,
        logDirectory: $this->workDir.'/logs',
        runtimeDirectory: $this->workDir.'/runtime',
        terminalPidTimeout: 0,
    );

    $record = $runner->startInTerminal(CommandLine::make('bun run dev'), 'assets-watch');

    expect($record->pid)->toBe(555)
        ->and($terminal->closed)->toBe(['42'])
        ->and($this->ledger->withLabel('assets-watch'))->toHaveCount(1);
});

test('isCommandAvailable reflects the probe exit code', function (): void {
    Process::fake(['*' => Process::result()]);

    expect(makeRunner($this->ledger, $this->workDir)->isCommandAvailable('php'))->toBeTrue();

    Process::fake(['*' => Process::result(exitCode: 1)]);

    expect(makeRunner($this->ledger, $this->workDir)->isCommandAvailable('nope'))->toBeFalse();
});
