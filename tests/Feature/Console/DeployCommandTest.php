<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Deploy\Steps\FinalizeApplication;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/boot-up-deploy-cmd-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);

    $this->store = new ActiveServerStore($this->workDir.'/active-server.json');
    app()->instance(ActiveServerStore::class, $this->store);
    app()->singleton(ProcessRunner::class, fn ($app) => new ProcessRunner(
        processes: $app->make(Factory::class),
        ledger: new ProcessLedger($this->workDir.'/processes.json'),
        logDirectory: $this->workDir.'/logs',
    ));

    config()->set('boot-up.deploy.steps', [FinalizeApplication::class]);
    config()->set('boot-up.deploy.auto_accept', true);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

test('runs the deploy pipeline without booting a server', function (): void {
    ProcessFaker::fake([
        'php artisan storage:link' => Process::result('The links have been created.'),
    ]);

    $this->artisan('app:deploy')->assertSuccessful();

    ProcessFaker::assertRan('php artisan storage:link');
    expect($this->store->current())->toBeNull();
});

test('shows the execution plan and a progress bar like app:setup', function (): void {
    ProcessFaker::fake([
        'php artisan storage:link' => Process::result('The links have been created.'),
    ]);

    $this->artisan('app:deploy')
        ->expectsOutputToContain('What app:deploy will do')
        ->expectsOutputToContain('Boot progress')
        ->expectsOutputToContain('Deploy complete.')
        ->assertSuccessful();
});

test('asks to continue and aborts when declined', function (): void {
    config()->set('boot-up.deploy.auto_accept', false);
    ProcessFaker::fake();

    $this->artisan('app:deploy')
        ->expectsConfirmation('Continue?', 'no')
        ->expectsOutputToContain('Aborted — nothing was changed.')
        ->assertSuccessful();

    ProcessFaker::assertDidntRun('php artisan storage:link');
});

test('the --yes flag skips the confirmation prompt', function (): void {
    config()->set('boot-up.deploy.auto_accept', false);
    ProcessFaker::fake([
        'php artisan storage:link' => Process::result('The links have been created.'),
    ]);

    $this->artisan('app:deploy', ['--yes' => true])->assertSuccessful();

    ProcessFaker::assertRan('php artisan storage:link');
});

test('a failing finalize command fails the deploy cleanly', function (): void {
    ProcessFaker::fake([
        'php artisan storage:link' => Process::result(output: 'boom', exitCode: 1),
    ]);

    $this->artisan('app:deploy')->assertFailed();
});

test('fails fast on native Windows', function (): void {
    ProcessFaker::fake();
    app()->instance(Igne\LaravelBootUp\Services\Platform::class, new Igne\LaravelBootUp\Services\Platform(Igne\LaravelBootUp\Enums\OperatingSystem::Windows));

    $this->artisan('app:deploy')
        ->expectsOutputToContain('not supported on native Windows')
        ->assertFailed();

    Process::assertNothingRan();
});

test('an unexpected exception fails cleanly instead of dumping a stack trace', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.deploy.steps', [Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\ExplodingStep::class]);

    $this->artisan('app:deploy')
        ->expectsOutputToContain('Unexpected error: something exploded')
        ->assertFailed();
});
