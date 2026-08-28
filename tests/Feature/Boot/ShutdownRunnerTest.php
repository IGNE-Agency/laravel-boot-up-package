<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Boot\ShutdownRunner;
use Igne\LaravelBootUp\Config\DevServerConfig;
use Igne\LaravelBootUp\Config\ShutdownConfig;
use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Frontend\ViteHotFile;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\ServerSelector;
use Igne\LaravelBootUp\Servers\StopServerPrompt;
use Igne\LaravelBootUp\Services\Poller;
use Igne\LaravelBootUp\Tests\Feature\Boot\Fixtures\RecordingServer;
use Igne\LaravelBootUp\Tests\Feature\Boot\Fixtures\ResidualServer;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/boot-up-shutdown-'.bin2hex(random_bytes(4));
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
function shutdownRunner(
    ProcessLedger $ledger,
    ActiveServerStore $store,
    bool $promptStop,
    bool $stopDefault,
    ?string $envDirectory = null,
): ShutdownRunner {
    $drivers = new DevServerConfig(drivers: ['double' => RecordingServer::class, 'residual' => ResidualServer::class]);
    $shutdown = new ShutdownConfig(promptStopServer: $promptStop, stopServerByDefault: $stopDefault);

    return new ShutdownRunner(
        $ledger,
        new ProcessReaper(app(Factory::class), $ledger, new Poller),
        $store,
        new ServerSelector(app(), $drivers, activeServerStore()),
        new StopServerPrompt($shutdown),
        app(ViteHotFile::class),
        envRestorePoint($envDirectory),
    );
}

function activeDouble(bool $startedByUs): ActiveServerRecord
{
    return new ActiveServerRecord('double', $startedByUs, (int) getmypid(), date(DATE_ATOM));
}

function activeResidual(bool $startedByUs): ActiveServerRecord
{
    return new ActiveServerRecord('residual', $startedByUs, (int) getmypid(), date(DATE_ATOM));
}

test('is a friendly no-op when nothing was started', function (): void {
    Prompt::fake();
    ProcessFaker::fake();

    shutdownRunner($this->ledger, $this->store, promptStop: false, stopDefault: true)->run();

    Prompt::assertStrippedOutputContains('Nothing to shut down.');
    Process::assertNothingRan();
    expect($this->server->stops)->toBe(0);
});

test('the .env the boot rewrote is put back once the server is down', function (): void {
    Prompt::fake();
    ProcessFaker::fake();
    file_put_contents($this->workDir.'/.env', "DB_HOST=127.0.0.1\nDB_USERNAME=root\n");
    $this->store->remember(activeDouble(startedByUs: true));

    $restore = envRestorePoint($this->workDir);
    $envFile = new EnvFile($this->workDir.'/.env', $this->workDir.'/.env.example');
    $restore->around(fn () => $envFile->setMany(['DB_HOST' => 'mysql', 'DB_USERNAME' => 'sail']));

    shutdownRunner($this->ledger, $this->store, promptStop: false, stopDefault: true, envDirectory: $this->workDir)->run();

    // The values described how that server reached its database; the next run
    // may not use that server at all.
    expect($envFile->get('DB_HOST'))->toBe('127.0.0.1')
        ->and($envFile->get('DB_USERNAME'))->toBe('root')
        ->and($this->server->stops)->toBe(1);
    Prompt::assertStrippedOutputContains('Restored DB_HOST, DB_USERNAME in .env.');
});

test('a boot that rewrote the .env and started nothing else is still undone', function (): void {
    Prompt::fake();
    ProcessFaker::fake();
    file_put_contents($this->workDir.'/.env', "DB_HOST=127.0.0.1\n");

    $restore = envRestorePoint($this->workDir);
    $envFile = new EnvFile($this->workDir.'/.env', $this->workDir.'/.env.example');
    $restore->around(fn () => $envFile->set('DB_HOST', 'mysql'));

    shutdownRunner($this->ledger, $this->store, promptStop: false, stopDefault: true, envDirectory: $this->workDir)->run();

    expect($envFile->get('DB_HOST'))->toBe('127.0.0.1');
    Prompt::assertStrippedOutputContains('Nothing to shut down.');
});

test('reaps tracked processes with TERM and clears the ledger', function (): void {
    Prompt::fake();
    $this->ledger->record(new ProcessRecord(4242, 'queue-worker', 'php artisan queue:work database', date(DATE_ATOM)));

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

    shutdownRunner($this->ledger, $this->store, promptStop: false, stopDefault: true)->run();

    ProcessFaker::assertRan('kill -TERM 4242');
    ProcessFaker::assertDidntRun('*KILL*');
    expect($this->ledger->isEmpty())->toBeTrue();
    Prompt::assertStrippedOutputContains('Stopping queue-worker (pid 4242)...');
    Prompt::assertStrippedOutputContains('Shutdown complete.');
});

test('removes a stale public/hot when a tracked asset watcher is torn down', function (): void {
    Prompt::fake();
    $this->ledger->record(new ProcessRecord(2000, 'vite', 'bun run dev', date(DATE_ATOM)));

    // The watcher is already gone, so reap settles it and clears the ledger.
    ProcessFaker::fake(['kill -0 2000' => Process::result(exitCode: 1)]);

    $hot = $this->workDir.'/hot';
    file_put_contents($hot, 'https://vite.example.test:5173');
    app()->instance(ViteHotFile::class, new ViteHotFile($hot));

    shutdownRunner($this->ledger, $this->store, promptStop: false, stopDefault: true)->run();

    expect(is_file($hot))->toBeFalse();
});

test('does not signal a recycled pid that started after the record', function (): void {
    Prompt::fake();
    $record = new ProcessRecord(4242, 'queue-worker', 'php artisan queue:work database', date(DATE_ATOM, time() - 3600));
    $this->ledger->record($record);

    ProcessFaker::fake([
        'kill -0 4242' => Process::result(),
        'ps -p 4242*' => Process::result('00:10'),
    ]);

    shutdownRunner($this->ledger, $this->store, promptStop: false, stopDefault: true)->run();

    ProcessFaker::assertDidntRun('kill -TERM 4242');
    ProcessFaker::assertDidntRun('pgrep*');
    expect($this->ledger->isEmpty())->toBeTrue();
});

test('keeps the ledger entry when a process cannot be stopped', function (): void {
    Prompt::fake();
    $this->ledger->record(new ProcessRecord(4242, 'queue-worker', 'php artisan queue:work database', date(DATE_ATOM)));

    ProcessFaker::fake([
        'kill -0 4242' => Process::result(),
        'ps -p 4242*' => Process::result('php artisan queue:work database'),
        'pkill*' => Process::result(),
        'kill -TERM 4242' => Process::result(),
        'kill -KILL 4242' => Process::result(),
    ]);

    (new ShutdownRunner(
        $this->ledger,
        new ProcessReaper(app(Factory::class), $this->ledger, new Poller, termGraceSeconds: 0, killGraceSeconds: 0),
        $this->store,
        new ServerSelector(app(), new DevServerConfig(drivers: ['double' => RecordingServer::class]), activeServerStore()),
        new StopServerPrompt(new ShutdownConfig(promptStopServer: false, stopServerByDefault: true)),
        app(ViteHotFile::class),
        envRestorePoint(),
    ))->run();

    expect($this->ledger->all())->toHaveCount(1);
    Prompt::assertStrippedOutputContains('Could not stop queue-worker (pid 4242)');
});

test('clears the active-server state and warns even when stopping the server throws', function (): void {
    Prompt::fake();
    ProcessFaker::fake();
    app()->instance(RecordingServer::class, $this->server = new RecordingServer(stopThrows: true));
    $this->store->remember(activeDouble(startedByUs: true));

    shutdownRunner($this->ledger, $this->store, promptStop: false, stopDefault: true)->run();

    expect($this->store->current())->toBeNull();
    Prompt::assertStrippedOutputContains('Could not stop Double Server');
});

test('stops only the server app:up started', function (): void {
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

test('prompts before stopping a server that was already running, keeping it by default', function (): void {
    Prompt::fake([Key::ENTER]);
    ProcessFaker::fake();
    $this->store->remember(activeDouble(startedByUs: false));

    shutdownRunner($this->ledger, $this->store, promptStop: true, stopDefault: false)->run();

    Prompt::assertStrippedOutputContains('Double Server was already running before the boot started.');
    Prompt::assertStrippedOutputContains('Stop Double Server?');
    expect($this->server->stops)->toBe(0)
        ->and($this->store->current())->toBeNull();
});

test('stops a server that was already running when explicitly confirmed', function (): void {
    Prompt::fake(['y', Key::ENTER]);
    ProcessFaker::fake();
    $this->store->remember(activeDouble(startedByUs: false));

    shutdownRunner($this->ledger, $this->store, promptStop: true, stopDefault: false)->run();

    expect($this->server->stops)->toBe(1);
    Prompt::assertStrippedOutputContains('Double Server stopped.');
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

test('offers residual cleanup after a failed boot and runs it on confirm', function (): void {
    Prompt::fake(['y', Key::ENTER]);
    ProcessFaker::fake();
    app()->instance(ResidualServer::class, $residual = new ResidualServer);
    $this->store->remember(activeResidual(startedByUs: true));

    shutdownRunner($this->ledger, $this->store, promptStop: true, stopDefault: false)->run();

    Prompt::assertStrippedOutputContains('Residual Server is not running, but the last boot did not finish cleanly.');
    Prompt::assertStrippedOutputContains("Clean up Residual Server's leftover resources?");
    Prompt::assertStrippedOutputContains('Residual Server cleaned up.');
    expect($residual->cleanUps)->toBe(1)
        ->and($this->store->current())->toBeNull();
});

test('declining the residual cleanup keeps the leftovers in place', function (): void {
    Prompt::fake(['n', Key::ENTER]);
    ProcessFaker::fake();
    app()->instance(ResidualServer::class, $residual = new ResidualServer);
    $this->store->remember(activeResidual(startedByUs: true));

    shutdownRunner($this->ledger, $this->store, promptStop: true, stopDefault: false)->run();

    Prompt::assertStrippedOutputContains("Keeping Residual Server's leftover resources in place.");
    expect($residual->cleanUps)->toBe(0)
        ->and($this->store->current())->toBeNull();
});

test('never offers residual cleanup for a server app:up did not start', function (): void {
    Prompt::fake();
    ProcessFaker::fake();
    app()->instance(ResidualServer::class, $residual = new ResidualServer);
    $this->store->remember(activeResidual(startedByUs: false));

    shutdownRunner($this->ledger, $this->store, promptStop: true, stopDefault: false)->run();

    expect($residual->cleanUps)->toBe(0);
});

test('a not-running server without the residual contract stays silent', function (): void {
    Prompt::fake();
    ProcessFaker::fake();
    app()->instance(RecordingServer::class, $this->server = new RecordingServer(running: false));
    $this->store->remember(activeDouble(startedByUs: true));

    shutdownRunner($this->ledger, $this->store, promptStop: true, stopDefault: false)->run();

    expect($this->server->stops)->toBe(0)
        ->and($this->store->current())->toBeNull();
    Prompt::assertStrippedOutputContains('Shutdown complete.');
});

test('no residual state means no cleanup offer', function (): void {
    Prompt::fake();
    ProcessFaker::fake();
    app()->instance(ResidualServer::class, $residual = new ResidualServer(residualState: false));
    $this->store->remember(activeResidual(startedByUs: true));

    shutdownRunner($this->ledger, $this->store, promptStop: true, stopDefault: false)->run();

    expect($residual->cleanUps)->toBe(0);
});

test('a failed cleanup warns but still completes the shutdown', function (): void {
    Prompt::fake(['y', Key::ENTER]);
    ProcessFaker::fake();
    app()->instance(ResidualServer::class, $residual = new ResidualServer(cleanUpThrows: true));
    $this->store->remember(activeResidual(startedByUs: true));

    shutdownRunner($this->ledger, $this->store, promptStop: true, stopDefault: false)->run();

    Prompt::assertStrippedOutputContains('Could not clean up Residual Server');
    Prompt::assertStrippedOutputContains('Shutdown complete.');
    expect($this->store->current())->toBeNull();
});

test('unattended shutdown only cleans residual state when stops default to yes', function (): void {
    Prompt::fake();
    ProcessFaker::fake();
    app()->instance(ResidualServer::class, $residual = new ResidualServer);
    $this->store->remember(activeResidual(startedByUs: true));

    shutdownRunner($this->ledger, $this->store, promptStop: false, stopDefault: false)->run();

    expect($residual->cleanUps)->toBe(0);

    app()->instance(ResidualServer::class, $residual = new ResidualServer);
    $this->store->remember(activeResidual(startedByUs: true));

    shutdownRunner($this->ledger, $this->store, promptStop: false, stopDefault: true)->run();

    expect($residual->cleanUps)->toBe(1);
});

test('the stale-hot-file cleanup looks for the asset watcher dev process', function (): void {
    $constant = new ReflectionClassConstant(ShutdownRunner::class, 'ASSET_WATCHER_LABEL');

    // The label is the name the process is registered and recorded under.
    expect($constant->getValue())->toBe('vite');
});
