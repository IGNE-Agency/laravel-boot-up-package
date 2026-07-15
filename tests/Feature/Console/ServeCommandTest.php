<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Process\ProcessLedger;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\Terminal\NullTerminal;
use Igne\LaravelBootstrap\Serve\Steps\AnnounceApplication;
use Igne\LaravelBootstrap\Servers\ActiveServer;
use Igne\LaravelBootstrap\Servers\ActiveServerStore;
use Igne\LaravelBootstrap\Servers\Steps\StartServer;
use Igne\LaravelBootstrap\Support\Poller;
use Igne\LaravelBootstrap\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/bootstrap-serve-cmd-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);

    $this->ledger = new ProcessLedger($this->workDir.'/processes.json');
    $this->store = new ActiveServerStore($this->workDir.'/active-server.json');

    app()->instance(ProcessLedger::class, $this->ledger);
    app()->instance(ActiveServerStore::class, $this->store);
    app()->singleton(ProcessRunner::class, fn ($app) => new ProcessRunner(
        processes: $app->make(Factory::class),
        ledger: $this->ledger,
        terminal: new NullTerminal,
        poller: new Poller,
        logDirectory: $this->workDir.'/logs',
        runtimeDirectory: $this->workDir.'/runtime',
    ));

    config()->set('bootstrap.serve_steps', [
        StartServer::class,
        AnnounceApplication::class,
    ]);
    config()->set('bootstrap.browser.open', false);
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
        ->and($records->first()->command)->toBe('php artisan serve');

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
    $this->ledger->record(new Igne\LaravelBootstrap\Process\ProcessRecord(12345, 'artisan-serve', 'php artisan serve', date(DATE_ATOM)));

    $this->artisan('app:serve', ['server' => 'laravel'])->assertSuccessful();

    ProcessFaker::assertDidntRun('sh -c nohup*');
    expect($this->ledger->withLabel('artisan-serve'))->toHaveCount(1);
});

test('aborts when another app:serve is already running for this project', function (): void {
    ProcessFaker::fake([
        'ps -p 99999*' => Process::result('php artisan app:serve laravel'),
    ]);

    $this->store->remember(new ActiveServer('laravel', true, 99999, date(DATE_ATOM)));

    $this->artisan('app:serve', ['server' => 'laravel'])->assertFailed();

    ProcessFaker::assertDidntRun('sh -c nohup*');
});

test('a stale active-server record from a dead process does not block a new serve', function (): void {
    ProcessFaker::fake([
        'ps -p 99999*' => Process::result(''),
        'sh -c nohup php artisan serve*' => Process::result('12345'),
    ]);

    $this->store->remember(new ActiveServer('laravel', true, 99999, date(DATE_ATOM)));

    $this->artisan('app:serve', ['server' => 'laravel'])->assertSuccessful();
});

test('a failing step surfaces as a clean failure, not a stack trace', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('app:serve', ['server' => 'laravel'])->assertFailed();
});

test('rejects an unknown server argument', function (): void {
    ProcessFaker::fake();

    $this->artisan('app:serve', ['server' => 'nginx'])->assertFailed();
})->throws(Igne\LaravelBootstrap\Servers\ServerException::class);
