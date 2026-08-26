<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Boot\ProjectReadiness;
use Igne\LaravelBootUp\Boot\Steps\AnnounceApplication;
use Igne\LaravelBootUp\Config\EnvironmentConfig;
use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Console\DevCommand;
use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Database\Steps\RunPendingMigrations;
use Igne\LaravelBootUp\Deploy\Steps\RunDeployTasks;
use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Enums\OperatingSystem;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\ViteHotFile;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\Steps\StartServer;
use Igne\LaravelBootUp\Services\Platform;
use Igne\LaravelBootUp\Tests\Feature\Boot\Fixtures\RecordingServer;
use Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\CapturingDevCommand;
use Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\ExplodingStep;
use Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\FailingStep;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/boot-up-setup-cmd-'.bin2hex(random_bytes(4));
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

    config()->set('boot-up.setup.steps', [
        StartServer::class,
        AnnounceApplication::class,
    ]);
    config()->set('boot-up.setup.open_browser', false);
    config()->set('boot-up.setup.auto_accept', true);

    // The artisan driver reserves its serve port for real. A machine with
    // something already on 8000 would fail every test in this file for a
    // reason none of them is about, so the check is opt-in per test.
    config()->set('boot-up.server.check_ports', false);

    // Testbench's skeleton ships a .env with QUEUE_CONNECTION=database, and
    // the .env is what a queue worker would actually run on. Point at the test's
    // own directory instead, so the baseline project has nothing for the dev
    // processes to do and each test only fakes what it asks for.
    app()->instance(EnvFile::class, new EnvFile($this->workDir.'/.env', $this->workDir.'/.env.example'));
    config()->set('queue.default', 'sync');

    // The boot now ends in the dev session, and a test has no terminal for
    // the multiplexer: capture the handover instead of starting one.
    $this->dev = new CapturingDevCommand;
    app()->instance(DevCommand::class, $this->dev);

    // The session dev takes over refuses to run against a project that is
    // not set up. These tests run a two-step pipeline that never writes a
    // .env, so stand the finished project up around it.
    file_put_contents($this->workDir.'/.env', "APP_ENV=local\nAPP_KEY=base64:x\n");
    mkdir($this->workDir.'/vendor', 0755, true);
    file_put_contents($this->workDir.'/vendor/autoload.php', '<?php');

    app()->instance(ProjectReadiness::class, new ProjectReadiness(
        envFile: app(EnvFile::class),
        packageJson: new PackageJson($this->workDir.'/package.json'),
        frontendConfig: new FrontendConfig(assets: AssetMode::Watch),
        environmentConfig: new EnvironmentConfig,
        basePath: $this->workDir,
        serverVars: [],
    ));
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

test('boots the artisan driver end to end and remembers what it set up', function (): void {
    ProcessFaker::fake();

    $this->artisan('app:up', ['server' => 'artisan'])->assertSuccessful();

    // The serve command is a dev process, so setup starts nothing behind it.
    ProcessFaker::assertDidntRun('sh -c nohup*');
    expect($this->ledger->withLabel('artisan-serve'))->toBeEmpty();

    // The record is written for the dev session to read and cleared again by
    // the teardown behind it, so the handover is the only place to see it.
    $active = $this->dev->activeAtHandoff;

    expect($active)->not->toBeNull()
        ->and($active->key)->toBe('artisan')
        ->and($active->startedByUs)->toBeTrue()
        ->and($active->setupPid)->toBe((int) getmypid())
        ->and($this->store->current())->toBeNull();
});

test('starts the dev command and names the processes it will run', function (): void {
    ProcessFaker::fake();
    config()->set('queue.default', 'database');

    // Artisan::call() rather than $this->artisan(): the whole block is one
    // write, and an artisan output expectation consumes a write for exactly
    // one of its substrings.
    Artisan::call('app:up', ['server' => 'artisan']);

    expect(Artisan::output())
        ->toContain('Starting php artisan dev')
        ->toContain('• server')
        ->toContain('• queue')
        ->toContain('Quit the dev terminal')
        ->and($this->dev->handoffs)->toBe(1);
});

test('the boot runs straight into the dev session, and stops it all again when it quits', function (): void {
    ProcessFaker::fake();

    Artisan::call('app:up', ['server' => 'artisan']);

    expect($this->dev->handoffs)->toBe(1)
        ->and(Artisan::output())->toContain('Stopping everything boot-up started...')
        // Nothing is left behind for a php artisan app:down to find.
        ->and($this->store->current())->toBeNull()
        ->and($this->ledger->isEmpty())->toBeTrue();
});

test('the dev session runs the server the boot picked, without consulting the record', function (): void {
    ProcessFaker::fake();
    $this->store->clear();

    $this->artisan('app:up', ['server' => 'artisan'])->assertSuccessful();

    expect(array_column($this->dev->handedOver, 'command'))
        ->toContain('php artisan serve --host=127.0.0.1 --port=8000');
});

test('--without-assets carries into the dev session', function (): void {
    ProcessFaker::fake();

    Artisan::call('app:up', ['server' => 'artisan', '--without-assets' => true]);

    expect(Artisan::output())->toContain('Assets skipped (--without-assets).');
});

test('a dev session that ends badly fails the command, and is torn down anyway', function (): void {
    ProcessFaker::fake();
    $this->dev->exitCode = 1;

    $this->artisan('app:up', ['server' => 'artisan'])->assertFailed();

    expect($this->dev->handoffs)->toBe(1)
        ->and($this->store->current())->toBeNull();
});

test('says so when there is nothing for the dev command to stream', function (): void {
    ProcessFaker::fake();
    // A driver that serves the project itself (as Herd does), a sync queue, no
    // Pail and no frontend leaves the registry empty.
    config()->set('boot-up.server.drivers.double', RecordingServer::class);
    config()->set('boot-up.dev.logs', false);
    config()->set('boot-up.frontend.assets', 'skip');

    $this->artisan('app:up', ['server' => 'double'])
        ->expectsOutputToContain('nothing for php artisan dev to stream')
        ->assertSuccessful();

    // Nothing to hand over means nothing to tear down either: the server the
    // boot started stays up, exactly as it would have before.
    expect($this->dev->handoffs)->toBe(0)
        ->and($this->store->current())->not->toBeNull();
});

test('does not start a second artisan serve when one is already tracked and alive', function (): void {
    ProcessFaker::fake([
        'kill -0 12345' => Process::result(),
        'ps -p 12345*' => Process::result('php artisan serve'),
    ]);

    // Seed a live artisan-serve record; the driver must self-skip.
    $this->ledger->record(new ProcessRecord(12345, 'artisan-serve', 'php artisan serve', date(DATE_ATOM)));

    $this->artisan('app:up', ['server' => 'artisan'])->assertSuccessful();

    ProcessFaker::assertDidntRun('sh -c nohup*');
    expect($this->ledger->withLabel('artisan-serve'))->toHaveCount(1);
});

test('aborts when another setup is already serving this project', function (): void {
    ProcessFaker::fake([
        'ps -p 99999*' => Process::result('php artisan app:up laravel'),
    ]);

    $this->store->remember(new ActiveServerRecord('artisan', true, 99999, date(DATE_ATOM)));

    $this->artisan('app:up', ['server' => 'artisan'])->assertFailed();

    ProcessFaker::assertDidntRun('sh -c nohup*');
});

test('a stale active-server record from a dead process does not block a new setup', function (): void {
    ProcessFaker::fake([
        'ps -p 99999*' => Process::result(''),
    ]);

    $this->store->remember(new ActiveServerRecord('artisan', true, 99999, date(DATE_ATOM)));

    $this->artisan('app:up', ['server' => 'artisan'])->assertSuccessful();
});

test('a failing step surfaces as a clean failure, not a stack trace', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [FailingStep::class]);

    $this->artisan('app:up', ['server' => 'artisan'])
        ->doesntExpectOutputToContain('Starting php artisan dev')
        ->assertFailed();
});

test('rejects an unknown server argument with a clean, actionable failure', function (): void {
    ProcessFaker::fake();

    $this->artisan('app:up', ['server' => 'nginx'])
        ->expectsOutputToContain('Unknown development server [nginx]')
        ->assertFailed();
});

test('fails fast on native Windows', function (): void {
    ProcessFaker::fake();
    app()->instance(Platform::class, new Platform(OperatingSystem::Windows));

    $this->artisan('app:up', ['server' => 'artisan'])
        ->expectsOutputToContain('not supported on native Windows')
        ->assertFailed();

    Process::assertNothingRan();
});

test('an unexpected exception fails cleanly with an app:down hint', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [ExplodingStep::class]);

    $this->artisan('app:up', ['server' => 'artisan'])
        ->expectsOutputToContain('Unexpected error: something exploded')
        ->expectsOutputToContain('php artisan app:down')
        ->assertFailed();
});

test('a taken server port fails with guidance rather than a bind crash', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.server.check_ports', true);

    // A port that is genuinely bound for the length of this test, so the
    // probe has something real to find.
    $socket = stream_socket_server('tcp://0.0.0.0:0');
    $name = (string) stream_socket_get_name($socket, false);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    config()->set('boot-up.artisan.port', $port);

    // Artisan::call() rather than $this->artisan(): the guidance block is one
    // write, and an artisan output expectation consumes a write for exactly
    // one of its substrings.
    $exitCode = Artisan::call('app:up', ['server' => 'artisan']);
    $output = Artisan::output();

    fclose($socket);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain("{$port} (php artisan serve)")
        ->and($output)->toContain('change boot-up.artisan.port')
        ->and($output)->toContain('php artisan app:down')
        // The guard runs ahead of the write-ahead record, so the failed boot
        // leaves nothing behind to clean up.
        ->and($this->store->current())->toBeNull();
});

test('a known mid-boot failure also shows the app:down hint', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [FailingStep::class]);

    $this->artisan('app:up', ['server' => 'artisan'])
        ->expectsOutputToContain('php artisan app:down')
        ->assertFailed();
});

test('prints the execution plan before booting', function (): void {
    ProcessFaker::fake();

    $this->artisan('app:up', ['server' => 'artisan'])
        ->expectsOutputToContain('What app:up will do')
        ->assertSuccessful();
});

test('the plan names the selected server', function (): void {
    ProcessFaker::fake();

    $this->artisan('app:up', ['server' => 'artisan'])
        ->expectsOutputToContain('development server')
        ->assertSuccessful();
});

test('asks to continue and aborts without changing anything when declined', function (): void {
    config()->set('boot-up.setup.auto_accept', false);
    ProcessFaker::fake();

    $this->artisan('app:up', ['server' => 'artisan'])
        ->expectsConfirmation('Continue?', 'no')
        ->expectsOutputToContain('Aborted — nothing was changed.')
        ->assertSuccessful();

    ProcessFaker::assertDidntRun('sh -c nohup*');
    expect($this->ledger->all())->toBeEmpty()
        ->and($this->store->current())->toBeNull();
});

test('the --yes flag skips the confirmation prompt', function (): void {
    config()->set('boot-up.setup.auto_accept', false);
    ProcessFaker::fake();

    // No expectsConfirmation: the run would fail on an unhandled prompt if
    // --yes did not skip it.
    $this->artisan('app:up', ['server' => 'artisan', '--yes' => true])->assertSuccessful();
});

test('renders a stage divider when the pipeline enters a stage', function (): void {
    ProcessFaker::fake();

    $this->artisan('app:up', ['server' => 'artisan'])
        ->expectsOutputToContain('Start server')
        ->assertSuccessful();
});

test('a later stage gets its own divider', function (): void {
    ProcessFaker::fake();

    $this->artisan('app:up', ['server' => 'artisan'])
        ->expectsOutputToContain('Announce the application')
        ->assertSuccessful();
});

test('the progress bar runs while the pipeline does', function (): void {
    ProcessFaker::fake();

    $this->artisan('app:up', ['server' => 'artisan'])
        ->expectsOutputToContain('Boot progress')
        ->assertSuccessful();
});

test('a custom step class gets the custom steps divider', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [ExplodingStep::class]);

    $this->artisan('app:up', ['server' => 'artisan'])
        ->expectsOutputToContain('Custom steps')
        ->assertFailed();
});

test('a Class:variant entry still resolves with its variant argument', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [RunDeployTasks::class.':before']);

    $this->artisan('app:up', ['server' => 'artisan'])->assertSuccessful();
});

test('--no-migrate hides the migrations plan line', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [
        StartServer::class,
        RunPendingMigrations::class,
        AnnounceApplication::class,
    ]);

    $this->artisan('app:up', ['server' => 'artisan', '--no-migrate' => true])
        ->doesntExpectOutputToContain('Run pending migrations')
        ->assertSuccessful();
});

test('the migrations plan line shows without --no-migrate', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [
        StartServer::class,
        RunPendingMigrations::class,
        AnnounceApplication::class,
    ]);

    $this->artisan('app:up', ['server' => 'artisan'])
        ->expectsOutputToContain('Run pending migrations')
        ->assertSuccessful();
});

test('dead ledger entries are pruned when a new setup runs', function (): void {
    ProcessFaker::fake([
        'kill -0 4444' => Process::result(exitCode: 1),
    ]);

    $this->ledger->record(new ProcessRecord(4444, 'queue-worker', 'php artisan queue:work database', date(DATE_ATOM)));

    $this->artisan('app:up', ['server' => 'artisan'])->assertSuccessful();

    expect($this->ledger->withLabel('queue-worker'))->toBeEmpty();
});

/**
 * The browser paths, which the rest of this file switches off in beforeEach.
 * A waiter that has already opened the browser is gone by teardown, so its
 * pid is faked dead — otherwise the reaper sits out its grace periods.
 */
function fakeBrowserWaiter(): void
{
    ProcessFaker::fake([
        'sh -c nohup*' => Process::result(output: "4242\n"),
        'kill -0 4242' => Process::result(exitCode: 1),
    ]);
}

/**
 * A project the asset watcher will actually run in: a dev script to run, and
 * the node_modules the dev session refuses to start without.
 */
function giveProjectAnAssetWatcher(string $workDir): void
{
    file_put_contents($workDir.'/package.json', json_encode(['scripts' => ['dev' => 'vite']]));
    mkdir($workDir.'/node_modules', 0755, true);

    app()->instance(PackageJson::class, new PackageJson($workDir.'/package.json'));
}

test('schedules the browser instead of opening one the page cannot fill yet', function (): void {
    // The regression: under the artisan driver, php artisan serve is itself a
    // dev process, so at this point nothing is listening on the announced URL.
    config()->set('boot-up.setup.open_browser', true);
    fakeBrowserWaiter();

    $this->artisan('app:up', ['server' => 'artisan'])->assertSuccessful();

    ProcessFaker::assertDidntRun('open http*');
    ProcessFaker::assertRan('sh -c nohup*app:open-browser http://127.0.0.1:8000*');
});

test('tells the browser to wait for Vite only when a watcher will run', function (): void {
    config()->set('boot-up.setup.open_browser', true);
    giveProjectAnAssetWatcher($this->workDir);
    fakeBrowserWaiter();

    $this->artisan('app:up', ['server' => 'artisan'])->assertSuccessful();

    ProcessFaker::assertRan('sh -c nohup*app:open-browser*--vite*');
});

test('waits without the Vite flag when this run has no asset watcher', function (): void {
    // The baseline project has no package.json, so nothing will write a hot
    // file and waiting for one would burn the whole timeout.
    config()->set('boot-up.setup.open_browser', true);
    fakeBrowserWaiter();

    $this->artisan('app:up', ['server' => 'artisan'])->assertSuccessful();

    ProcessFaker::assertRan('sh -c nohup*app:open-browser*');
    ProcessFaker::assertDidntRun('*--vite*');
});

test('a hot file left behind by a killed watcher is cleared before the browser waits', function (): void {
    config()->set('boot-up.setup.open_browser', true);
    giveProjectAnAssetWatcher($this->workDir);
    app()->instance(ViteHotFile::class, new ViteHotFile($hot = $this->workDir.'/hot'));
    file_put_contents($hot, 'http://[::1]:5173');
    fakeBrowserWaiter();

    $this->artisan('app:up', ['server' => 'artisan'])->assertSuccessful();

    // Otherwise it reads as "Vite is serving" the moment the waiter looks,
    // and the page loads assets from a dev server that is gone.
    expect(is_file($hot))->toBeFalse();
});

test('a browser that cannot be scheduled does not fail the boot', function (): void {
    config()->set('boot-up.setup.open_browser', true);
    // No pid echoed back, so ProcessRunner::start() throws.
    ProcessFaker::fake(['sh -c nohup*' => Process::result(output: '')]);

    $this->artisan('app:up', ['server' => 'artisan'])
        ->expectsOutputToContain('Could not schedule the browser')
        ->assertSuccessful();

    expect($this->dev->handoffs)->toBe(1);
});

test('--seed keeps its short flag, matching app:deploy', function (): void {
    $definition = Artisan::all()['app:up']->getDefinition();

    expect($definition->getOption('seed')->getShortcut())->toBe('s')
        ->and($definition->getOption('update')->getShortcut())->toBe('u')
        ->and($definition->hasOption('without-assets'))->toBeTrue()
        // --without-queue and --detach belong to dev: only the dev processes
        // read them, and setup starts none.
        ->and($definition->hasOption('without-queue'))->toBeFalse()
        ->and($definition->hasOption('detach'))->toBeFalse();
});
