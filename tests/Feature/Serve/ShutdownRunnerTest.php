<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Process\ProcessLedger;
use Igne\LaravelBootstrap\Process\ProcessReaper;
use Igne\LaravelBootstrap\Process\ProcessRecord;
use Igne\LaravelBootstrap\Serve\ShutdownRunner;
use Igne\LaravelBootstrap\Servers\ActiveServer;
use Igne\LaravelBootstrap\Servers\ActiveServerStore;
use Igne\LaravelBootstrap\Servers\ServersConfig;
use Igne\LaravelBootstrap\Servers\ServerSelector;
use Igne\LaravelBootstrap\Servers\StopServerPrompt;
use Igne\LaravelBootstrap\Support\Poller;
use Igne\LaravelBootstrap\Tests\Feature\Serve\Fixtures\RecordingServer;
use Igne\LaravelBootstrap\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/bootstrap-shutdown-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);
    $this->ledger = new ProcessLedger($this->workDir.'/processes.json');
    $this->store = new ActiveServerStore($this->workDir.'/active-server.json');
    $this->server = new RecordingServer;
    app()->instance(RecordingServer::class, $this->server);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

/**
 * Build the runner AFTER Process/Prompt fakes are active so the reaper
 * receives the fake process factory.
 */
function shutdownRunner(ProcessLedger $ledger, ActiveServerStore $store, bool $promptStop, bool $stopDefault): ShutdownRunner
{
    $config = new ServersConfig(
        drivers: ['double' => RecordingServer::class],
        promptStopServer: $promptStop,
        stopServerByDefault: $stopDefault,
    );

    return new ShutdownRunner(
        $ledger,
        new ProcessReaper(app(Factory::class), $ledger, new Poller),
        $store,
        new ServerSelector(app(), $config),
        new StopServerPrompt($config),
    );
}

function activeDouble(bool $startedByUs): ActiveServer
{
    return new ActiveServer('double', $startedByUs, (int) getmypid(), date(DATE_ATOM));
}

test('is a friendly no-op when nothing was started', function (): void {
    Prompt::fake();
    ProcessFaker::fake();

    shutdownRunner($this->ledger, $this->store, promptStop: false, stopDefault: true)->run();

    Prompt::assertStrippedOutputContains('Nothing to shut down.');
    Process::assertNothingRan();
    expect($this->server->stops)->toBe(0);
});

test('reaps tracked processes with TERM (children first) and clears the ledger', function (): void {
    Prompt::fake();
    $this->ledger->record(new ProcessRecord(4242, 'queue-worker', 'php artisan queue:work database', date(DATE_ATOM)));

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

    shutdownRunner($this->ledger, $this->store, promptStop: false, stopDefault: true)->run();

    ProcessFaker::assertRan('pkill -TERM -P 4242');
    ProcessFaker::assertRan('kill -TERM 4242');
    ProcessFaker::assertDidntRun('*KILL*');
    expect($this->ledger->isEmpty())->toBeTrue();
    Prompt::assertStrippedOutputContains('Stopping queue-worker (pid 4242)...');
    Prompt::assertStrippedOutputContains('Shutdown complete.');
});

test('does not signal a recycled pid whose command no longer matches', function (): void {
    Prompt::fake();
    $this->ledger->record(new ProcessRecord(4242, 'queue-worker', 'php artisan queue:work database', date(DATE_ATOM)));

    ProcessFaker::fake([
        'kill -0 4242' => Process::result(),
        'ps -p 4242*' => Process::result('some-unrelated-daemon --serve'),
    ]);

    shutdownRunner($this->ledger, $this->store, promptStop: false, stopDefault: true)->run();

    ProcessFaker::assertDidntRun('kill -TERM 4242');
    ProcessFaker::assertDidntRun('pkill*');
    expect($this->ledger->isEmpty())->toBeTrue();
});

test('stops only the server app:serve started', function (): void {
    Prompt::fake();
    ProcessFaker::fake();
    $this->store->remember(activeDouble(startedByUs: true));

    shutdownRunner($this->ledger, $this->store, promptStop: false, stopDefault: true)->run();

    expect($this->server->stops)->toBe(1)
        ->and($this->store->current())->toBeNull();
    Prompt::assertStrippedOutputContains('Double Server stopped.');
});

test('prompts before stopping and honours the answer', function (): void {
    Prompt::fake(['y', Key::ENTER]);
    ProcessFaker::fake();
    $this->store->remember(activeDouble(startedByUs: true));

    shutdownRunner($this->ledger, $this->store, promptStop: true, stopDefault: false)->run();

    Prompt::assertStrippedOutputContains('Stop Double Server?');
    expect($this->server->stops)->toBe(1);
});

test('keeps the server when the prompt is declined', function (): void {
    Prompt::fake([Key::ENTER]);
    ProcessFaker::fake();
    $this->store->remember(activeDouble(startedByUs: true));

    shutdownRunner($this->ledger, $this->store, promptStop: true, stopDefault: false)->run();

    expect($this->server->stops)->toBe(0)
        ->and($this->store->current())->toBeNull();
    Prompt::assertStrippedOutputContains('Keeping Double Server running.');
});

test('leaves a server alone that was already running before app:serve', function (): void {
    Prompt::fake();
    ProcessFaker::fake();
    $this->store->remember(activeDouble(startedByUs: false));

    shutdownRunner($this->ledger, $this->store, promptStop: true, stopDefault: false)->run();

    expect($this->server->stops)->toBe(0)
        ->and($this->store->current())->toBeNull();
    Prompt::assertStrippedOutputContains('Leaving double running');
});

test('a second invocation on the same instance is a silent no-op', function (): void {
    Prompt::fake();
    ProcessFaker::fake();
    $this->store->remember(activeDouble(startedByUs: true));

    $runner = shutdownRunner($this->ledger, $this->store, promptStop: false, stopDefault: true);
    $runner->run();
    $runner->run();

    expect($this->server->stops)->toBe(1);
});

test('a later shutdown after state was cleared reports nothing to do', function (): void {
    Prompt::fake();
    ProcessFaker::fake();
    $this->store->remember(activeDouble(startedByUs: true));

    shutdownRunner($this->ledger, $this->store, promptStop: false, stopDefault: true)->run();
    shutdownRunner($this->ledger, $this->store, promptStop: false, stopDefault: true)->run();

    expect($this->server->stops)->toBe(1);
    Prompt::assertStrippedOutputContains('Nothing to shut down.');
});
