<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRecord;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Process\Terminal\NullTerminal;
use Igne\LaravelBootUp\Serve\ServeContext;
use Igne\LaravelBootUp\Serve\ServeOptions;
use Igne\LaravelBootUp\Services\ServicesConfig;
use Igne\LaravelBootUp\Services\Steps\StartHorizon;
use Igne\LaravelBootUp\Services\Steps\StartReverb;
use Igne\LaravelBootUp\Services\Steps\StartScheduler;
use Igne\LaravelBootUp\Support\Poller;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

/**
 * Call AFTER Process::fake() so the runner and reaper receive the faked
 * factory.
 *
 * @param  array<string, mixed>  $composer
 */
function bindServiceDeps(string $dir, ?ServicesConfig $config = null, array $composer = []): ProcessLedger
{
    $ledger = new ProcessLedger($dir.'/processes.json');

    file_put_contents($dir.'/composer.json', json_encode($composer));

    app()->instance(ServicesConfig::class, $config ?? new ServicesConfig);
    app()->instance(ComposerJson::class, new ComposerJson($dir.'/composer.json'));
    app()->instance(ProcessLedger::class, $ledger);
    app()->instance(ProcessReaper::class, new ProcessReaper(app(Factory::class), $ledger, new Poller));
    app()->instance(ProcessRunner::class, new ProcessRunner(
        processes: app(Factory::class),
        ledger: $ledger,
        terminal: new NullTerminal,
        poller: new Poller,
        logDirectory: $dir.'/logs',
        runtimeDirectory: $dir.'/runtime',
    ));

    return $ledger;
}

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-services-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    Prompt::fake();
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

test('the scheduler stays off by default', function (): void {
    Process::fake();
    bindServiceDeps($this->dir);

    $context = new ServeContext(new ServeOptions);

    $result = app(StartScheduler::class)->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertNothingRan();
});

test('an enabled scheduler starts a tracked schedule:work process', function (): void {
    ProcessFaker::fake(['*' => Process::result(output: "4242\n")]);
    $ledger = bindServiceDeps($this->dir, new ServicesConfig(schedulerEnabled: true));

    app(StartScheduler::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    ProcessFaker::assertRan('*nohup php artisan schedule:work*scheduler.log*');
    expect($ledger->withLabel('scheduler'))->toHaveCount(1);
    Prompt::assertStrippedOutputContains('Scheduler started (PID 4242)');
});

test('a scheduler that is already running is not started twice', function (): void {
    ProcessFaker::fake([
        'kill -0 4242' => Process::result(),
        'ps -p 4242*' => Process::result('php artisan schedule:work'),
    ]);
    $ledger = bindServiceDeps($this->dir, new ServicesConfig(schedulerEnabled: true));
    $ledger->record(new ProcessRecord(4242, 'scheduler', 'php artisan schedule:work', date(DATE_ATOM)));

    app(StartScheduler::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    ProcessFaker::assertDidntRun('*nohup*');
    Prompt::assertStrippedOutputContains('Scheduler already running');
});

test('horizon is skipped when the project does not require it', function (): void {
    Process::fake();
    bindServiceDeps($this->dir, composer: ['require' => ['laravel/framework' => '^13.0']]);

    app(StartHorizon::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertNothingRan();
});

test('horizon starts when the project requires it', function (): void {
    ProcessFaker::fake(['*' => Process::result(output: "4242\n")]);
    $ledger = bindServiceDeps($this->dir, composer: ['require' => ['laravel/horizon' => '^6.0']]);

    app(StartHorizon::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    ProcessFaker::assertRan('*nohup php artisan horizon*horizon.log*');
    expect($ledger->withLabel('horizon'))->toHaveCount(1);
    Prompt::assertStrippedOutputContains('Horizon started (PID 4242)');
});

test('horizon can be disabled in configuration even when installed', function (): void {
    Process::fake();
    bindServiceDeps($this->dir, new ServicesConfig(horizonEnabled: false), ['require' => ['laravel/horizon' => '^6.0']]);

    app(StartHorizon::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertNothingRan();
});

test('reverb starts when the project requires it', function (): void {
    ProcessFaker::fake(['*' => Process::result(output: "4242\n")]);
    $ledger = bindServiceDeps($this->dir, composer: ['require' => ['laravel/reverb' => '^2.0']]);

    app(StartReverb::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    ProcessFaker::assertRan('*nohup php artisan reverb:start*reverb.log*');
    expect($ledger->withLabel('reverb'))->toHaveCount(1);
});

test('reverb is skipped when the project does not require it', function (): void {
    Process::fake();
    bindServiceDeps($this->dir, composer: ['require' => []]);

    app(StartReverb::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertNothingRan();
});
