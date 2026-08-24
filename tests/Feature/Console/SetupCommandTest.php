<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Boot\Steps\AnnounceApplication;
use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Database\Steps\RunPendingMigrations;
use Igne\LaravelBootUp\Deploy\Steps\RunDeployTasks;
use Igne\LaravelBootUp\Enums\OperatingSystem;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\Steps\StartServer;
use Igne\LaravelBootUp\Services\Platform;
use Igne\LaravelBootUp\Tests\Feature\Boot\Fixtures\RecordingServer;
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

    // Testbench's skeleton ships a .env with QUEUE_CONNECTION=database, and
    // the .env is what a queue worker would actually run on. Point at the test's
    // own directory instead, so the baseline project has nothing for the dev
    // processes to do and each test only fakes what it asks for.
    app()->instance(EnvFile::class, new EnvFile($this->workDir.'/.env', $this->workDir.'/.env.example'));
    config()->set('queue.default', 'sync');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

test('boots the artisan driver end to end and remembers what it set up', function (): void {
    ProcessFaker::fake();

    $this->artisan('app:setup', ['server' => 'artisan'])->assertSuccessful();

    // The serve command is a dev process, so setup starts nothing behind it.
    ProcessFaker::assertDidntRun('sh -c nohup*');
    expect($this->ledger->withLabel('artisan-serve'))->toBeEmpty();

    $active = $this->store->current();
    expect($active)->not->toBeNull()
        ->and($active->key)->toBe('artisan')
        ->and($active->startedByUs)->toBeTrue()
        ->and($active->setupPid)->toBe((int) getmypid());
});

test('names the dev command and the processes it will run', function (): void {
    ProcessFaker::fake();
    config()->set('queue.default', 'database');

    // Artisan::call() rather than $this->artisan(): the whole block is one
    // write, and an artisan output expectation consumes a write for exactly
    // one of its substrings.
    Artisan::call('app:setup', ['server' => 'artisan']);

    expect(Artisan::output())
        ->toContain('Next: php artisan dev')
        ->toContain('• server')
        ->toContain('• queue')
        ->toContain('Stop the server with: php artisan app:down');
});

test('says so when there is nothing for the dev command to stream', function (): void {
    ProcessFaker::fake();
    // A driver that serves the project itself (as Herd does), a sync queue, no
    // Pail and no frontend leaves the registry empty.
    config()->set('boot-up.server.drivers.double', RecordingServer::class);
    config()->set('boot-up.dev.logs', false);
    config()->set('boot-up.frontend.assets', 'skip');

    $this->artisan('app:setup', ['server' => 'double'])
        ->expectsOutputToContain('nothing for php artisan dev to stream')
        ->assertSuccessful();
});

test('does not start a second artisan serve when one is already tracked and alive', function (): void {
    ProcessFaker::fake([
        'kill -0 12345' => Process::result(),
        'ps -p 12345*' => Process::result('php artisan serve'),
    ]);

    // Seed a live artisan-serve record; the driver must self-skip.
    $this->ledger->record(new ProcessRecord(12345, 'artisan-serve', 'php artisan serve', date(DATE_ATOM)));

    $this->artisan('app:setup', ['server' => 'artisan'])->assertSuccessful();

    ProcessFaker::assertDidntRun('sh -c nohup*');
    expect($this->ledger->withLabel('artisan-serve'))->toHaveCount(1);
});

test('aborts when another setup is already serving this project', function (): void {
    ProcessFaker::fake([
        'ps -p 99999*' => Process::result('php artisan app:setup laravel'),
    ]);

    $this->store->remember(new ActiveServerRecord('artisan', true, 99999, date(DATE_ATOM)));

    $this->artisan('app:setup', ['server' => 'artisan'])->assertFailed();

    ProcessFaker::assertDidntRun('sh -c nohup*');
});

test('a stale active-server record from a dead process does not block a new setup', function (): void {
    ProcessFaker::fake([
        'ps -p 99999*' => Process::result(''),
    ]);

    $this->store->remember(new ActiveServerRecord('artisan', true, 99999, date(DATE_ATOM)));

    $this->artisan('app:setup', ['server' => 'artisan'])->assertSuccessful();
});

test('a failing step surfaces as a clean failure, not a stack trace', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [FailingStep::class]);

    $this->artisan('app:setup', ['server' => 'artisan'])
        ->doesntExpectOutputToContain('Next: php artisan dev')
        ->assertFailed();
});

test('rejects an unknown server argument with a clean, actionable failure', function (): void {
    ProcessFaker::fake();

    $this->artisan('app:setup', ['server' => 'nginx'])
        ->expectsOutputToContain('Unknown development server [nginx]')
        ->assertFailed();
});

test('fails fast on native Windows', function (): void {
    ProcessFaker::fake();
    app()->instance(Platform::class, new Platform(OperatingSystem::Windows));

    $this->artisan('app:setup', ['server' => 'artisan'])
        ->expectsOutputToContain('not supported on native Windows')
        ->assertFailed();

    Process::assertNothingRan();
});

test('an unexpected exception fails cleanly with an app:down hint', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [ExplodingStep::class]);

    $this->artisan('app:setup', ['server' => 'artisan'])
        ->expectsOutputToContain('Unexpected error: something exploded')
        ->expectsOutputToContain('php artisan app:down')
        ->assertFailed();
});

test('a known mid-boot failure also shows the app:down hint', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [FailingStep::class]);

    $this->artisan('app:setup', ['server' => 'artisan'])
        ->expectsOutputToContain('php artisan app:down')
        ->assertFailed();
});

test('prints the execution plan before booting', function (): void {
    ProcessFaker::fake();

    $this->artisan('app:setup', ['server' => 'artisan'])
        ->expectsOutputToContain('What app:setup will do')
        ->assertSuccessful();
});

test('the plan names the selected server', function (): void {
    ProcessFaker::fake();

    $this->artisan('app:setup', ['server' => 'artisan'])
        ->expectsOutputToContain('development server')
        ->assertSuccessful();
});

test('asks to continue and aborts without changing anything when declined', function (): void {
    config()->set('boot-up.setup.auto_accept', false);
    ProcessFaker::fake();

    $this->artisan('app:setup', ['server' => 'artisan'])
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
    $this->artisan('app:setup', ['server' => 'artisan', '--yes' => true])->assertSuccessful();
});

test('renders a stage divider when the pipeline enters a stage', function (): void {
    ProcessFaker::fake();

    $this->artisan('app:setup', ['server' => 'artisan'])
        ->expectsOutputToContain('Start server')
        ->assertSuccessful();
});

test('a later stage gets its own divider', function (): void {
    ProcessFaker::fake();

    $this->artisan('app:setup', ['server' => 'artisan'])
        ->expectsOutputToContain('Announce the application')
        ->assertSuccessful();
});

test('the progress bar runs while the pipeline does', function (): void {
    ProcessFaker::fake();

    $this->artisan('app:setup', ['server' => 'artisan'])
        ->expectsOutputToContain('Boot progress')
        ->assertSuccessful();
});

test('a custom step class gets the custom steps divider', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [ExplodingStep::class]);

    $this->artisan('app:setup', ['server' => 'artisan'])
        ->expectsOutputToContain('Custom steps')
        ->assertFailed();
});

test('a Class:variant entry still resolves with its variant argument', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [RunDeployTasks::class.':before']);

    $this->artisan('app:setup', ['server' => 'artisan'])->assertSuccessful();
});

test('--no-migrate hides the migrations plan line', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [
        StartServer::class,
        RunPendingMigrations::class,
        AnnounceApplication::class,
    ]);

    $this->artisan('app:setup', ['server' => 'artisan', '--no-migrate' => true])
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

    $this->artisan('app:setup', ['server' => 'artisan'])
        ->expectsOutputToContain('Run pending migrations')
        ->assertSuccessful();
});

test('dead ledger entries are pruned when a new setup runs', function (): void {
    ProcessFaker::fake([
        'kill -0 4444' => Process::result(exitCode: 1),
    ]);

    $this->ledger->record(new ProcessRecord(4444, 'queue-worker', 'php artisan queue:work database', date(DATE_ATOM)));

    $this->artisan('app:setup', ['server' => 'artisan'])->assertSuccessful();

    expect($this->ledger->withLabel('queue-worker'))->toBeEmpty();
});

test('--seed keeps its short flag, matching app:deploy', function (): void {
    $definition = Artisan::all()['app:setup']->getDefinition();

    expect($definition->getOption('seed')->getShortcut())->toBe('s')
        ->and($definition->getOption('update')->getShortcut())->toBe('u')
        ->and($definition->hasOption('without-assets'))->toBeTrue()
        // --without-queue and --detach belong to dev: only the dev processes
        // read them, and setup starts none.
        ->and($definition->hasOption('without-queue'))->toBeFalse()
        ->and($definition->hasOption('detach'))->toBeFalse();
});
