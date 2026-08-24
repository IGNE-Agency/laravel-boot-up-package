<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Boot\Steps\AnnounceApplication;
use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\Steps\StartServer;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/boot-up-serve-cmd-'.bin2hex(random_bytes(4));
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

test('boots the artisan driver end to end: tracked artisan serve + persisted state', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    $this->artisan('dev', ['server' => 'artisan'])->assertSuccessful();

    ProcessFaker::assertRan('sh -c nohup php artisan serve*');

    $records = $this->ledger->withLabel('artisan-serve');
    expect($records)->toHaveCount(1)
        ->and($records->first()->pid)->toBe(12345)
        ->and($records->first()->command)->toBe('php artisan serve --host=127.0.0.1 --port=8000');

    $active = $this->store->current();
    expect($active)->not->toBeNull()
        ->and($active->key)->toBe('artisan')
        ->and($active->startedByUs)->toBeTrue()
        ->and($active->servePid)->toBe((int) getmypid());
});

test('does not start a second artisan serve when one is already tracked and alive', function (): void {
    ProcessFaker::fake([
        'kill -0 12345' => Process::result(),
        'ps -p 12345*' => Process::result('php artisan serve'),
    ]);

    // Seed a live artisan-serve record; the driver must self-skip.
    $this->ledger->record(new Igne\LaravelBootUp\Data\ProcessRecord(12345, 'artisan-serve', 'php artisan serve', date(DATE_ATOM)));

    $this->artisan('dev', ['server' => 'artisan'])->assertSuccessful();

    ProcessFaker::assertDidntRun('sh -c nohup*');
    expect($this->ledger->withLabel('artisan-serve'))->toHaveCount(1);
});

test('aborts when the application is already being served for this project', function (): void {
    ProcessFaker::fake([
        'ps -p 99999*' => Process::result('php artisan app:serve laravel'),
    ]);

    $this->store->remember(new ActiveServerRecord('artisan', true, 99999, date(DATE_ATOM)));

    $this->artisan('dev', ['server' => 'artisan'])->assertFailed();

    ProcessFaker::assertDidntRun('sh -c nohup*');
});

test('a stale active-server record from a dead process does not block a new serve', function (): void {
    ProcessFaker::fake([
        'ps -p 99999*' => Process::result(''),
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    $this->store->remember(new ActiveServerRecord('artisan', true, 99999, date(DATE_ATOM)));

    $this->artisan('dev', ['server' => 'artisan'])->assertSuccessful();
});

test('a failing step surfaces as a clean failure, not a stack trace', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('dev', ['server' => 'artisan'])->assertFailed();
});

test('rejects an unknown server argument with a clean, actionable failure', function (): void {
    ProcessFaker::fake();

    $this->artisan('dev', ['server' => 'nginx'])
        ->expectsOutputToContain('Unknown development server [nginx]')
        ->assertFailed();
});

test('fails fast on native Windows', function (): void {
    ProcessFaker::fake();
    app()->instance(Igne\LaravelBootUp\Services\Platform::class, new Igne\LaravelBootUp\Services\Platform(Igne\LaravelBootUp\Enums\OperatingSystem::Windows));

    $this->artisan('dev', ['server' => 'artisan'])
        ->expectsOutputToContain('not supported on native Windows')
        ->assertFailed();

    Process::assertNothingRan();
});

test('an unexpected exception fails cleanly with an app:down hint', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\ExplodingStep::class]);

    $this->artisan('dev', ['server' => 'artisan'])
        ->expectsOutputToContain('Unexpected error: something exploded')
        ->expectsOutputToContain('php artisan app:down')
        ->assertFailed();
});

test('a known mid-boot failure also shows the app:down hint', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\FailingStep::class]);

    $this->artisan('dev', ['server' => 'artisan'])
        ->expectsOutputToContain('php artisan app:down')
        ->assertFailed();
});

test('prints the execution plan before booting', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    $this->artisan('dev', ['server' => 'artisan'])
        ->expectsOutputToContain('What dev will do')
        ->assertSuccessful();
});

test('the plan names the selected server', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    $this->artisan('dev', ['server' => 'artisan'])
        ->expectsOutputToContain('development server')
        ->assertSuccessful();
});

test('asks to continue and aborts without changing anything when declined', function (): void {
    config()->set('boot-up.setup.auto_accept', false);
    ProcessFaker::fake();

    $this->artisan('dev', ['server' => 'artisan'])
        ->expectsConfirmation('Continue?', 'no')
        ->expectsOutputToContain('Aborted — nothing was changed.')
        ->assertSuccessful();

    ProcessFaker::assertDidntRun('sh -c nohup*');
    expect($this->ledger->all())->toBeEmpty()
        ->and($this->store->current())->toBeNull();
});

test('the --yes flag skips the confirmation prompt', function (): void {
    config()->set('boot-up.setup.auto_accept', false);
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    // No expectsConfirmation: the run would fail on an unhandled prompt if
    // --yes did not skip it.
    $this->artisan('dev', ['server' => 'artisan', '--yes' => true])->assertSuccessful();

    ProcessFaker::assertRan('sh -c nohup php artisan serve*');
});

test('renders a stage divider when the pipeline enters a stage', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    $this->artisan('dev', ['server' => 'artisan'])
        ->expectsOutputToContain('Start server')
        ->assertSuccessful();
});

test('a later stage gets its own divider', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    $this->artisan('dev', ['server' => 'artisan'])
        ->expectsOutputToContain('Announce the application')
        ->assertSuccessful();
});

test('the progress bar runs and the boot ends with an outro', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    $this->artisan('dev', ['server' => 'artisan'])
        ->expectsOutputToContain('Boot progress')
        ->expectsOutputToContain('Application ready.')
        ->assertSuccessful();
});

test('a custom step class gets the custom steps divider', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\ExplodingStep::class]);

    $this->artisan('dev', ['server' => 'artisan'])
        ->expectsOutputToContain('Custom steps')
        ->assertFailed();
});

test('a Class:variant entry still resolves with its variant argument', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [
        Igne\LaravelBootUp\Deploy\Steps\RunDeployTasks::class.':before',
    ]);

    $this->artisan('dev', ['server' => 'artisan'])->assertSuccessful();
});

test('--no-migrate hides the migrations plan line', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);
    config()->set('boot-up.setup.steps', [
        StartServer::class,
        Igne\LaravelBootUp\Database\Steps\RunPendingMigrations::class,
        AnnounceApplication::class,
    ]);

    $this->artisan('dev', ['server' => 'artisan', '--no-migrate' => true])
        ->doesntExpectOutputToContain('Run pending migrations')
        ->assertSuccessful();
});

test('the migrations plan line shows without --no-migrate', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);
    config()->set('boot-up.setup.steps', [
        StartServer::class,
        Igne\LaravelBootUp\Database\Steps\RunPendingMigrations::class,
        AnnounceApplication::class,
    ]);

    $this->artisan('dev', ['server' => 'artisan'])
        ->expectsOutputToContain('Run pending migrations')
        ->assertSuccessful();
});

test('dead ledger entries are pruned when a new serve boots', function (): void {
    ProcessFaker::fake([
        'kill -0 4444' => Process::result(exitCode: 1),
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    $this->ledger->record(new Igne\LaravelBootUp\Data\ProcessRecord(4444, 'queue-worker', 'php artisan queue:work database', date(DATE_ATOM)));

    $this->artisan('dev', ['server' => 'artisan'])->assertSuccessful();

    expect($this->ledger->withLabel('queue-worker'))->toBeEmpty();
});

test('the dev processes start in the background when there is no terminal to run them in', function (): void {
    // The test runner's stdout is a pipe, so follow resolves to false --
    // exactly the CI/scripted case the detached path exists for.
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
        'sh -c nohup php artisan queue:work*' => Process::result('12346'),
    ]);
    config()->set('queue.default', 'database');

    $this->artisan('dev', ['server' => 'artisan'])
        ->expectsOutputToContain('[queue] running in the background (PID 12346).')
        ->assertSuccessful();

    ProcessFaker::assertRan('sh -c nohup php artisan queue:work database*');
    expect($this->ledger->withLabel('queue'))->toHaveCount(1);
});

test('--detach runs the dev processes in the background', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
        'sh -c nohup php artisan queue:work*' => Process::result('12346'),
    ]);
    config()->set('queue.default', 'database');

    $this->artisan('dev', ['server' => 'artisan', '--detach' => true])
        ->expectsOutputToContain('the dev processes run in the background')
        ->assertSuccessful();

    ProcessFaker::assertRan('sh -c nohup php artisan queue:work database*');
});

test('a project with nothing to run starts no dev processes', function (): void {
    ProcessFaker::fake(['sh -c nohup php artisan serve*' => Process::result('12345')]);
    config()->set('queue.default', 'sync');

    $this->artisan('dev', ['server' => 'artisan'])->assertSuccessful();

    ProcessFaker::assertDidntRun('sh -c nohup php artisan queue:work*');
});

test('the old app:serve name still works and says it is going away', function (): void {
    ProcessFaker::fake(['sh -c nohup php artisan serve*' => Process::result('12345')]);

    $this->artisan('app:serve', ['server' => 'artisan'])
        ->expectsOutputToContain('app:serve is now `php artisan dev`')
        ->assertSuccessful();
});

test('a foreground boot hands the dev processes to the framework, then tears down', function (): void {
    ProcessFaker::fake();
    config()->set('queue.default', 'database');
    config()->set('boot-up.shutdown.prompt_stop_server', false);

    $command = new Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\CapturingDevCommand;
    app()->instance(Igne\LaravelBootUp\Console\DevCommand::class, $command);

    $this->artisan('dev', ['server' => 'artisan'])
        ->expectsOutputToContain('Shutdown complete.')
        ->assertSuccessful();

    expect($command->handoffs)->toBe(1)
        ->and(array_column($command->handedOver, 'name'))->toBe(['server', 'queue'])
        ->and(array_column($command->handedOver, 'command'))->toBe([
            'php artisan serve --host=127.0.0.1 --port=8000',
            'php artisan queue:work database',
        ]);

    // The server runs as a dev process now, so nothing was launched behind it.
    ProcessFaker::assertDidntRun('sh -c nohup*');
});

test('php artisan dev resolves to boot-up\'s command, not the framework\'s', function (): void {
    $resolved = Illuminate\Support\Facades\Artisan::all()['dev'];

    expect($resolved)->toBeInstanceOf(Igne\LaravelBootUp\Console\DevCommand::class)
        ->and($resolved)->toBeInstanceOf(Illuminate\Foundation\Console\DevCommand::class)
        ->and($resolved->getDescription())->toContain('Boot everything the application needs');
});

test('the framework binding resolves to the same command', function (): void {
    expect(app(Illuminate\Foundation\Console\DevCommand::class))
        ->toBeInstanceOf(Igne\LaravelBootUp\Console\DevCommand::class);
});

test('dev keeps every option the framework defines and adds boot-up\'s', function (): void {
    $definition = Illuminate\Support\Facades\Artisan::all()['dev']->getDefinition();

    expect($definition->hasOption('tabs'))->toBeTrue()
        ->and($definition->hasOption('stream'))->toBeTrue()
        ->and($definition->hasOption('inline'))->toBeTrue()
        ->and($definition->hasOption('no-restart'))->toBeTrue()
        ->and($definition->hasOption('detach'))->toBeTrue()
        ->and($definition->hasOption('without-queue'))->toBeTrue()
        ->and($definition->hasArgument('server'))->toBeTrue()
        // -s stays with the framework's --stream, so --seed has no shortcut.
        ->and($definition->getOption('stream')->getShortcut())->toBe('s')
        ->and($definition->getOption('seed')->getShortcut())->toBeNull();
});

test('app:serve is registered as an alias of dev', function (): void {
    expect(Illuminate\Support\Facades\Artisan::all()['dev']->getAliases())->toContain('app:serve');
});
