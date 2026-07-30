<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Process\NullTerminalLauncher;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Serve\Steps\AnnounceApplication;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\Steps\StartServer;
use Igne\LaravelBootUp\Services\Poller;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;

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
        terminal: new NullTerminalLauncher,
        poller: new Poller,
        logDirectory: $this->workDir.'/logs',
        runtimeDirectory: $this->workDir.'/runtime',
    ));

    config()->set('boot-up.serve.steps', [
        StartServer::class,
        AnnounceApplication::class,
    ]);
    config()->set('boot-up.serve.open_browser', false);
    config()->set('boot-up.serve.auto_accept', true);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

test('boots the laravel driver end to end: tracked artisan serve + persisted state', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    $this->artisan('app:serve', ['server' => 'laravel'])->assertSuccessful();

    ProcessFaker::assertRan('sh -c nohup php artisan serve*');

    $records = $this->ledger->withLabel('artisan-serve');
    expect($records)->toHaveCount(1)
        ->and($records->first()->pid)->toBe(12345)
        ->and($records->first()->command)->toBe('php artisan serve --host=127.0.0.1 --port=8000');

    $active = $this->store->current();
    expect($active)->not->toBeNull()
        ->and($active->key)->toBe('laravel')
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

    $this->artisan('app:serve', ['server' => 'laravel'])->assertSuccessful();

    ProcessFaker::assertDidntRun('sh -c nohup*');
    expect($this->ledger->withLabel('artisan-serve'))->toHaveCount(1);
});

test('aborts when another app:serve is already running for this project', function (): void {
    ProcessFaker::fake([
        'ps -p 99999*' => Process::result('php artisan app:serve laravel'),
    ]);

    $this->store->remember(new ActiveServerRecord('laravel', true, 99999, date(DATE_ATOM)));

    $this->artisan('app:serve', ['server' => 'laravel'])->assertFailed();

    ProcessFaker::assertDidntRun('sh -c nohup*');
});

test('a stale active-server record from a dead process does not block a new serve', function (): void {
    ProcessFaker::fake([
        'ps -p 99999*' => Process::result(''),
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    $this->store->remember(new ActiveServerRecord('laravel', true, 99999, date(DATE_ATOM)));

    $this->artisan('app:serve', ['server' => 'laravel'])->assertSuccessful();
});

test('a failing step surfaces as a clean failure, not a stack trace', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('app:serve', ['server' => 'laravel'])->assertFailed();
});

test('rejects an unknown server argument with a clean, actionable failure', function (): void {
    ProcessFaker::fake();

    $this->artisan('app:serve', ['server' => 'nginx'])
        ->expectsOutputToContain('Unknown development server [nginx]')
        ->assertFailed();
});

test('fails fast on native Windows', function (): void {
    ProcessFaker::fake();
    app()->instance(Igne\LaravelBootUp\Services\Platform::class, new Igne\LaravelBootUp\Services\Platform('Windows'));

    $this->artisan('app:serve', ['server' => 'laravel'])
        ->expectsOutputToContain('not supported on native Windows')
        ->assertFailed();

    Process::assertNothingRan();
});

test('an unexpected exception fails cleanly with an app:down hint', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.serve.steps', [Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\ExplodingStep::class]);

    $this->artisan('app:serve', ['server' => 'laravel'])
        ->expectsOutputToContain('Unexpected error: something exploded')
        ->expectsOutputToContain('php artisan app:down')
        ->assertFailed();
});

test('a known mid-boot failure also shows the app:down hint', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.serve.steps', [Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\FailingStep::class]);

    $this->artisan('app:serve', ['server' => 'laravel'])
        ->expectsOutputToContain('php artisan app:down')
        ->assertFailed();
});

test('prints the execution plan before booting', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    $this->artisan('app:serve', ['server' => 'laravel'])
        ->expectsOutputToContain('What app:serve will do')
        ->assertSuccessful();
});

test('the plan names the selected server', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    $this->artisan('app:serve', ['server' => 'laravel'])
        ->expectsOutputToContain('development server')
        ->assertSuccessful();
});

test('asks to continue and aborts without changing anything when declined', function (): void {
    config()->set('boot-up.serve.auto_accept', false);
    ProcessFaker::fake();

    $this->artisan('app:serve', ['server' => 'laravel'])
        ->expectsConfirmation('Continue?', 'no')
        ->expectsOutputToContain('Aborted — nothing was changed.')
        ->assertSuccessful();

    ProcessFaker::assertDidntRun('sh -c nohup*');
    expect($this->ledger->all())->toBeEmpty()
        ->and($this->store->current())->toBeNull();
});

test('the --yes flag skips the confirmation prompt', function (): void {
    config()->set('boot-up.serve.auto_accept', false);
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    // No expectsConfirmation: the run would fail on an unhandled prompt if
    // --yes did not skip it.
    $this->artisan('app:serve', ['server' => 'laravel', '--yes' => true])->assertSuccessful();

    ProcessFaker::assertRan('sh -c nohup php artisan serve*');
});

test('renders a stage divider when the pipeline enters a stage', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    $this->artisan('app:serve', ['server' => 'laravel'])
        ->expectsOutputToContain('Start server')
        ->assertSuccessful();
});

test('a later stage gets its own divider', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    $this->artisan('app:serve', ['server' => 'laravel'])
        ->expectsOutputToContain('Announce the application')
        ->assertSuccessful();
});

test('the progress bar runs and the boot ends with an outro', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    $this->artisan('app:serve', ['server' => 'laravel'])
        ->expectsOutputToContain('Boot progress')
        ->expectsOutputToContain('Application ready.')
        ->assertSuccessful();
});

test('a custom step class gets the custom steps divider', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.serve.steps', [Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\ExplodingStep::class]);

    $this->artisan('app:serve', ['server' => 'laravel'])
        ->expectsOutputToContain('Custom steps')
        ->assertFailed();
});

test('a Class:variant entry still resolves with its variant argument', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.serve.steps', [
        Igne\LaravelBootUp\Deploy\Steps\RunDeployTasks::class.':before',
    ]);

    $this->artisan('app:serve', ['server' => 'laravel'])->assertSuccessful();
});

test('--no-migrate hides the migrations plan line', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);
    config()->set('boot-up.serve.steps', [
        StartServer::class,
        Igne\LaravelBootUp\Database\Steps\RunPendingMigrations::class,
        AnnounceApplication::class,
    ]);

    $this->artisan('app:serve', ['server' => 'laravel', '--no-migrate' => true])
        ->doesntExpectOutputToContain('Run pending migrations')
        ->assertSuccessful();
});

test('the migrations plan line shows without --no-migrate', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);
    config()->set('boot-up.serve.steps', [
        StartServer::class,
        Igne\LaravelBootUp\Database\Steps\RunPendingMigrations::class,
        AnnounceApplication::class,
    ]);

    $this->artisan('app:serve', ['server' => 'laravel'])
        ->expectsOutputToContain('Run pending migrations')
        ->assertSuccessful();
});

test('a combined worker degrades to the background when stdout is not interactive', function (): void {
    // The test runner's stdout is a pipe, so follow resolves to false —
    // exactly the CI/scripted case the fallback exists for.
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
        'sh -c nohup php artisan queue:work*' => Process::result('12346'),
    ]);
    config()->set('boot-up.serve.steps', [
        StartServer::class,
        Igne\LaravelBootUp\Queue\Steps\StartQueueWorker::class,
        AnnounceApplication::class,
    ]);
    config()->set('queue.default', 'database');

    $this->artisan('app:serve', ['server' => 'laravel'])
        ->expectsOutputToContain('no interactive terminal to stream into — running in the background instead.')
        ->assertSuccessful();

    ProcessFaker::assertRan('sh -c nohup php artisan queue:work*');
    expect($this->ledger->withLabel('queue-worker'))->toHaveCount(1);
});

test('--detach is accepted and keeps the boot fully detached', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
        'sh -c nohup php artisan queue:work*' => Process::result('12346'),
    ]);
    config()->set('boot-up.serve.steps', [
        StartServer::class,
        Igne\LaravelBootUp\Queue\Steps\StartQueueWorker::class,
        AnnounceApplication::class,
    ]);
    config()->set('queue.default', 'database');

    $this->artisan('app:serve', ['server' => 'laravel', '--detach' => true])
        ->expectsOutputToContain('Application ready.')
        ->assertSuccessful();

    ProcessFaker::assertRan('sh -c nohup php artisan queue:work*');
});

test('dead ledger entries are pruned when a new serve boots', function (): void {
    ProcessFaker::fake([
        'kill -0 4444' => Process::result(exitCode: 1),
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    $this->ledger->record(new Igne\LaravelBootUp\Data\ProcessRecord(4444, 'queue-worker', 'php artisan queue:work database', date(DATE_ATOM)));

    $this->artisan('app:serve', ['server' => 'laravel'])->assertSuccessful();

    expect($this->ledger->withLabel('queue-worker'))->toBeEmpty();
});
