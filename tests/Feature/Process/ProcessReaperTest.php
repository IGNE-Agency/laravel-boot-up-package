<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Contracts\TerminalLauncher;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\Terminal\NullTerminal;
use Igne\LaravelBootUp\Services\Poller;
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
function reaper(ProcessLedger $ledger, ?TerminalLauncher $terminal = null): ProcessReaper
{
    return new ProcessReaper(
        app(Factory::class),
        $ledger,
        new Poller,
        $terminal ?? new NullTerminal,
        termGraceSeconds: 0,
        killGraceSeconds: 0,
    );
}

/**
 * A terminal launcher that records the window handles it is asked to close.
 */
function recordingTerminal(): TerminalLauncher
{
    return new class implements TerminalLauncher
    {
        /** @var list<string> */
        public array $closed = [];

        public function available(): bool
        {
            return true;
        }

        public function open(string $command, ?string $directory = null): ?string
        {
            return null;
        }

        public function close(?string $handle): void
        {
            if ($handle !== null) {
                $this->closed[] = $handle;
            }
        }
    };
}

function workerRecord(int $pid = 4242, ?string $startedAt = null, ?string $window = null): ProcessRecord
{
    return new ProcessRecord($pid, 'queue-worker', 'php artisan queue:work database', $startedAt ?? date(DATE_ATOM), $window);
}

test('a process that survives KILL stays in the ledger with a warning', function (): void {
    Prompt::fake();
    $this->ledger->record(workerRecord());

    ProcessFaker::fake([
        'kill -0 4242' => Process::result(),
        'ps -p 4242*' => Process::result('00:10'),
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
        'ps -p 4242*' => fn () => Process::result('00:05'),
        'kill -TERM 4242' => function () use (&$alive) {
            $alive = false;

            return Process::result();
        },
    ]);

    $reaped = reaper($this->ledger)->reap(workerRecord());

    expect($reaped)->toBeTrue()
        ->and($this->ledger->isEmpty())->toBeTrue();
    ProcessFaker::assertDidntRun('*KILL*');
});

test('a recycled pid that started after the record is treated as gone, not signalled', function (): void {
    Prompt::fake();
    $record = workerRecord(startedAt: date(DATE_ATOM, time() - 3600));
    $this->ledger->record($record);

    // Alive, but only running for 10s — it cannot be the process we recorded
    // an hour ago, so the pid was reused and ours is gone.
    ProcessFaker::fake([
        'kill -0 4242' => Process::result(),
        'ps -p 4242*' => Process::result('00:10'),
    ]);

    $reaped = reaper($this->ledger)->reap($record);

    ProcessFaker::assertDidntRun('kill -TERM*');
    ProcessFaker::assertDidntRun('pgrep*');
    expect($reaped)->toBeTrue()
        ->and($this->ledger->isEmpty())->toBeTrue();
});

test('a live process is identified by its start time, not its command line', function (): void {
    Prompt::fake();
    $this->ledger->record(workerRecord());

    // No command is ever inspected; a recent start time consistent with the
    // record is enough to treat the pid as ours.
    ProcessFaker::fake([
        'kill -0 4242' => Process::result(),
        'ps -p 4242*' => Process::result('00:30'),
    ]);

    expect(reaper($this->ledger)->isAlive(workerRecord()))->toBeTrue();
});

test('signals the whole descendant tree, deepest first', function (): void {
    Prompt::fake();
    $this->ledger->record(workerRecord());

    $alive = true;
    ProcessFaker::fake([
        'pgrep -P 4242' => Process::result("100\n200"),
        'pgrep -P 100' => Process::result('300'),
        // TERM on the recorded pid takes the whole snapshotted tree down.
        'kill -TERM 4242' => function () use (&$alive) {
            $alive = false;

            return Process::result();
        },
        // Liveness is checked across every pid in the snapshot, not just 4242.
        'kill -0 *' => function () use (&$alive) {
            return Process::result(exitCode: $alive ? 0 : 1);
        },
        'ps -p 4242*' => fn () => Process::result('00:05'),
    ]);

    $reaped = reaper($this->ledger)->reap(workerRecord());

    expect($reaped)->toBeTrue();
    ProcessFaker::assertRan('kill -TERM 300');
    ProcessFaker::assertRan('kill -TERM 100');
    ProcessFaker::assertRan('kill -TERM 200');
    ProcessFaker::assertRan('kill -TERM 4242');
    // The KILL pass never runs because the tree is gone after TERM.
    ProcessFaker::assertDidntRun('kill -KILL*');
});

test('closes the terminal window once the process is gone', function (): void {
    Prompt::fake();
    $terminal = recordingTerminal();
    $this->ledger->record(workerRecord(window: '55'));

    $alive = true;
    ProcessFaker::fake([
        'kill -0 4242' => function () use (&$alive) {
            return Process::result(exitCode: $alive ? 0 : 1);
        },
        'ps -p 4242*' => fn () => Process::result('00:05'),
        'kill -TERM 4242' => function () use (&$alive) {
            $alive = false;

            return Process::result();
        },
    ]);

    reaper($this->ledger, $terminal)->reap(workerRecord(window: '55'));

    expect($terminal->closed)->toBe(['55']);
});

test('prune drops dead entries and keeps live ones without signalling', function (): void {
    Prompt::fake();
    $this->ledger->record(workerRecord(pid: 1000));
    $this->ledger->record(new ProcessRecord(2000, 'assets-watch', 'bun run dev', date(DATE_ATOM)));

    ProcessFaker::fake([
        'kill -0 1000' => Process::result(exitCode: 1),
        'kill -0 2000' => Process::result(),
        'ps -p 2000*' => Process::result('00:10'),
    ]);

    reaper($this->ledger)->prune();

    ProcessFaker::assertDidntRun('kill -TERM*');
    ProcessFaker::assertDidntRun('pgrep*');
    expect($this->ledger->all())->toHaveCount(1)
        ->and($this->ledger->all()->first()->pid)->toBe(2000);
});
