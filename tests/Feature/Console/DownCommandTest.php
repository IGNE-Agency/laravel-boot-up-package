<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Process\ProcessLedger;
use Igne\LaravelBootstrap\Process\ProcessRecord;
use Igne\LaravelBootstrap\Serve\ShutdownRunner;
use Igne\LaravelBootstrap\Servers\ActiveServer;
use Igne\LaravelBootstrap\Servers\ActiveServerStore;
use Igne\LaravelBootstrap\Tests\Feature\Serve\Fixtures\RecordingServer;
use Igne\LaravelBootstrap\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/bootstrap-down-cmd-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);

    $this->ledger = new ProcessLedger($this->workDir.'/processes.json');
    $this->store = new ActiveServerStore($this->workDir.'/active-server.json');
    $this->server = new RecordingServer;

    app()->instance(ProcessLedger::class, $this->ledger);
    app()->instance(ActiveServerStore::class, $this->store);
    app()->instance(RecordingServer::class, $this->server);

    config()->set('bootstrap.server.drivers', ['double' => RecordingServer::class]);
    config()->set('bootstrap.shutdown.prompt_stop_server', false);
    config()->set('bootstrap.shutdown.stop_server_by_default', true);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

test('reaps tracked processes and stops only the persisted server', function (): void {
    $this->ledger->record(new ProcessRecord(4242, 'queue-worker', 'php artisan queue:work database', date(DATE_ATOM)));
    $this->store->remember(new ActiveServer('double', true, (int) getmypid(), date(DATE_ATOM)));

    ProcessFaker::fake([
        'kill -0 4242' => Process::result(exitCode: 1),
    ]);

    $this->artisan('app:down')->assertSuccessful();

    expect($this->server->stops)->toBe(1)
        ->and($this->ledger->isEmpty())->toBeTrue()
        ->and($this->store->current())->toBeNull();
});

test('leaves a pre-existing server running and clears the state', function (): void {
    $this->store->remember(new ActiveServer('double', false, (int) getmypid(), date(DATE_ATOM)));

    ProcessFaker::fake();

    $this->artisan('app:down')->assertSuccessful();

    expect($this->server->stops)->toBe(0)
        ->and($this->store->current())->toBeNull();
});

test('a second app:down after everything was cleaned is a friendly no-op', function (): void {
    $this->store->remember(new ActiveServer('double', true, (int) getmypid(), date(DATE_ATOM)));

    ProcessFaker::fake();

    $this->artisan('app:down')->assertSuccessful();

    // A new process would resolve a fresh ShutdownRunner; simulate that.
    app()->forgetInstance(ShutdownRunner::class);

    $this->artisan('app:down')->assertSuccessful();

    expect($this->server->stops)->toBe(1);
});
