<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\ShellCommand;
use Igne\LaravelBootUp\Process\Terminal\NullTerminal;
use Igne\LaravelBootUp\Support\Poller;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;

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
        terminal: new NullTerminal,
        poller: new Poller,
        logDirectory: $workDir.'/logs',
        runtimeDirectory: $workDir.'/runtime',
    );
}

test('run throws on failure', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'boom')]);

    makeRunner($this->ledger, $this->workDir)->run(ShellCommand::make('composer install'));
})->throws(ProcessFailedException::class);

test('runSilently never throws on failure and returns the result', function (): void {
    Process::fake(['*' => Process::result(exitCode: 127)]);

    $result = makeRunner($this->ledger, $this->workDir)->runSilently(ShellCommand::make('command -v missing'));

    expect($result->successful())->toBeFalse()->and($result->exitCode())->toBe(127);
});

test('start spawns a detached nohup wrapper and records the pid in the ledger', function (): void {
    Process::fake(['*' => Process::result(output: "4242\n")]);

    $record = makeRunner($this->ledger, $this->workDir)
        ->start(ShellCommand::make('php artisan serve'), 'artisan-serve');

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

    makeRunner($this->ledger, $this->workDir)->start(ShellCommand::make('php artisan serve'), 'artisan-serve');
})->throws(Igne\LaravelBootUp\Process\ProcessException::class);

test('startInTerminal degrades to a tracked background start when no terminal exists', function (): void {
    Process::fake(['*' => Process::result(output: "77\n")]);

    $record = makeRunner($this->ledger, $this->workDir)
        ->startInTerminal(ShellCommand::make('bun run dev'), 'assets-watch');

    expect($record->pid)->toBe(77)
        ->and($this->ledger->withLabel('assets-watch'))->toHaveCount(1);
});

test('isCommandAvailable reflects the probe exit code', function (): void {
    Process::fake(['*' => Process::result()]);

    expect(makeRunner($this->ledger, $this->workDir)->isCommandAvailable('php'))->toBeTrue();

    Process::fake(['*' => Process::result(exitCode: 1)]);

    expect(makeRunner($this->ledger, $this->workDir)->isCommandAvailable('nope'))->toBeFalse();
});
