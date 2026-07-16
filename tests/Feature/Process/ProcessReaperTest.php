<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRecord;
use Igne\LaravelBootUp\Support\Poller;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/boot-up-reaper-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);
    $this->ledger = new ProcessLedger($this->workDir.'/processes.json');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

/**
 * Zero grace periods so a surviving process fails fast instead of
 * sleeping through the real TERM/KILL windows.
 */
function reaper(ProcessLedger $ledger): ProcessReaper
{
    return new ProcessReaper(app(Factory::class), $ledger, new Poller, termGraceSeconds: 0, killGraceSeconds: 0);
}

function workerRecord(int $pid = 4242): ProcessRecord
{
    return new ProcessRecord($pid, 'queue-worker', 'php artisan queue:work database', date(DATE_ATOM));
}

test('a process that survives KILL stays in the ledger with a warning', function (): void {
    Prompt::fake();
    $this->ledger->record(workerRecord());

    ProcessFaker::fake([
        'kill -0 4242' => Process::result(),
        'ps -p 4242*' => Process::result('php artisan queue:work database'),
        'pkill*' => Process::result(),
        'kill -TERM 4242' => Process::result(),
        'kill -KILL 4242' => Process::result(),
    ]);

    $reaped = reaper($this->ledger)->reap(workerRecord());

    ProcessFaker::assertRan('kill -KILL 4242');
    expect($reaped)->toBeFalse()
        ->and($this->ledger->all())->toHaveCount(1);
    Prompt::assertStrippedOutputContains('Could not stop queue-worker (pid 4242)');
});

test('a confirmed kill forgets the ledger entry and reports success', function (): void {
    Prompt::fake();
    $this->ledger->record(workerRecord());

    $alive = true;
    ProcessFaker::fake([
        'kill -0 4242' => function () use (&$alive) {
            return Process::result(exitCode: $alive ? 0 : 1);
        },
        'ps -p 4242*' => fn () => Process::result('php artisan queue:work database'),
        'pkill -TERM -P 4242' => function () use (&$alive) {
            $alive = false;

            return Process::result();
        },
        'kill -TERM 4242' => Process::result(),
    ]);

    $reaped = reaper($this->ledger)->reap(workerRecord());

    expect($reaped)->toBeTrue()
        ->and($this->ledger->isEmpty())->toBeTrue();
    ProcessFaker::assertDidntRun('*KILL*');
});

test('a recycled pid running the same binary with other arguments is not signalled', function (): void {
    Prompt::fake();
    $this->ledger->record(workerRecord());

    ProcessFaker::fake([
        'kill -0 4242' => Process::result(),
        'ps -p 4242*' => Process::result('php /usr/local/bin/some-other-tool --daemon'),
    ]);

    $reaped = reaper($this->ledger)->reap(workerRecord());

    ProcessFaker::assertDidntRun('kill -TERM*');
    ProcessFaker::assertDidntRun('pkill*');
    expect($reaped)->toBeTrue()
        ->and($this->ledger->isEmpty())->toBeTrue();
});

test('a rewritten command line that contains the recorded arguments still matches', function (): void {
    Prompt::fake();
    $this->ledger->record(workerRecord());

    ProcessFaker::fake([
        'kill -0 4242' => Process::result(),
        'ps -p 4242*' => Process::result('/opt/homebrew/bin/php artisan queue:work database'),
    ]);

    expect(reaper($this->ledger)->isAlive(workerRecord()))->toBeTrue();
});

test('prune drops dead entries and keeps live ones without signalling', function (): void {
    Prompt::fake();
    $this->ledger->record(workerRecord(pid: 1000));
    $this->ledger->record(new ProcessRecord(2000, 'assets-watch', 'bun run dev', date(DATE_ATOM)));

    ProcessFaker::fake([
        'kill -0 1000' => Process::result(exitCode: 1),
        'kill -0 2000' => Process::result(),
        'ps -p 2000*' => Process::result('bun run dev'),
    ]);

    reaper($this->ledger)->prune();

    ProcessFaker::assertDidntRun('kill -TERM*');
    ProcessFaker::assertDidntRun('pkill*');
    expect($this->ledger->all())->toHaveCount(1)
        ->and($this->ledger->all()->first()->pid)->toBe(2000);
});
