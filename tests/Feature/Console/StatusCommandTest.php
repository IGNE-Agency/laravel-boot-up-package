<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/boot-up-status-cmd-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);

    $this->ledger = new ProcessLedger($this->workDir.'/processes.json');
    $this->store = new ActiveServerStore($this->workDir.'/active-server.json');

    app()->instance(ProcessLedger::class, $this->ledger);
    app()->instance(ActiveServerStore::class, $this->store);
    app()->singleton(ProcessRunner::class, fn ($app) => new ProcessRunner(
        processes: $app->make(Factory::class),
        ledger: $this->ledger,
        logDirectory: $this->workDir.'/logs',
    ));
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

test('reports that nothing is running on a clean project', function (): void {
    ProcessFaker::fake();

    $this->artisan('app:status')
        ->expectsOutputToContain('Application status')
        ->expectsOutputToContain('Nothing is running.')
        ->assertSuccessful();

    Process::assertNothingRan();
});

test('shows the active server, its serve pid state, and every tracked process', function (): void {
    ProcessFaker::fake([
        'ps -p 99999*' => Process::result('php artisan app:serve laravel'),
        'kill -0 4242' => Process::result(),
        'ps -p 4242*' => Process::result('php artisan queue:work database'),
        'kill -0 5555' => Process::result(exitCode: 1),
    ]);

    $this->store->remember(new ActiveServerRecord('artisan', true, 99999, date(DATE_ATOM)));
    $this->ledger->record(new ProcessRecord(4242, 'queue-worker', 'php artisan queue:work database', date(DATE_ATOM)));
    $this->ledger->record(new ProcessRecord(5555, 'assets-watch', 'bun run dev', date(DATE_ATOM)));

    $this->artisan('app:status')
        ->expectsOutputToContain('Laravel (php artisan serve) at http://127.0.0.1:8000')
        ->expectsOutputToContain('The server was started by php artisan dev.')
        ->expectsOutputToContain('php artisan dev is running (pid 99999).')
        ->expectsOutputToContain('queue-worker (pid 4242): running — logs: storage/logs/boot-up/queue-worker.log')
        ->expectsOutputToContain('assets-watch (pid 5555): dead — logs: storage/logs/boot-up/assets-watch.log')
        ->expectsOutputToContain('php artisan app:down')
        ->assertSuccessful();
});

test('a dead serve pid is reported, not hidden', function (): void {
    ProcessFaker::fake([
        'ps -p 99999*' => Process::result(''),
    ]);

    $this->store->remember(new ActiveServerRecord('artisan', false, 99999, date(DATE_ATOM)));

    $this->artisan('app:status')
        ->expectsOutputToContain('The server was already running before php artisan dev started.')
        ->expectsOutputToContain('Its php artisan dev (pid 99999) is no longer running.')
        ->assertSuccessful();
});

test('status is read-only: dead ledger entries survive it', function (): void {
    ProcessFaker::fake([
        'kill -0 5555' => Process::result(exitCode: 1),
    ]);

    $this->ledger->record(new ProcessRecord(5555, 'assets-watch', 'bun run dev', date(DATE_ATOM)));

    $this->artisan('app:status')->assertSuccessful();

    expect($this->ledger->all())->toHaveCount(1);
    ProcessFaker::assertDidntRun('kill -TERM*');
});
