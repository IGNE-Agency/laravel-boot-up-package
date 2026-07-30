<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\HorizonConfig;
use Igne\LaravelBootUp\Config\ReverbConfig;
use Igne\LaravelBootUp\Config\SchedulerConfig;
use Igne\LaravelBootUp\Contracts\TerminalLauncher;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Enums\RunMode;
use Igne\LaravelBootUp\Pipelines\ComposerJson;
use Igne\LaravelBootUp\Process\NullTerminalLauncher;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Serve\CombinedRunPlan;
use Igne\LaravelBootUp\Services\Poller;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Igne\LaravelBootUp\Workers\Steps\StartHorizon;
use Igne\LaravelBootUp\Workers\Steps\StartReverb;
use Igne\LaravelBootUp\Workers\Steps\StartScheduler;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

/**
 * Call AFTER Process::fake() so the runner and reaper receive the faked
 * factory.
 *
 * @param  array<string, mixed>  $composer
 */
function bindWorkerDeps(string $dir, HorizonConfig|ReverbConfig|SchedulerConfig|null $config = null, array $composer = [], ?TerminalLauncher $terminal = null): ProcessLedger
{
    $ledger = new ProcessLedger($dir.'/processes.json');

    file_put_contents($dir.'/composer.json', json_encode($composer));

    if ($config !== null) {
        app()->instance($config::class, $config);
    }
    app()->instance(ComposerJson::class, new ComposerJson($dir.'/composer.json'));
    app()->instance(ProcessLedger::class, $ledger);
    app()->instance(ProcessReaper::class, new ProcessReaper(app(Factory::class), $ledger, new Poller, new NullTerminalLauncher));
    app()->instance(ProcessRunner::class, new ProcessRunner(
        processes: app(Factory::class),
        ledger: $ledger,
        terminal: $terminal ?? new NullTerminalLauncher,
        poller: new Poller,
        logDirectory: $dir.'/logs',
        runtimeDirectory: $dir.'/runtime',
    ));

    return $ledger;
}

/**
 * A terminal that records what it opens and writes the pid file the
 * runner's shim would normally write, so startInTerminal() completes.
 */
function fakeServiceTerminal(): TerminalLauncher
{
    return new class implements TerminalLauncher
    {
        /** @var list<string> */
        public array $opened = [];

        public function available(): bool
        {
            return true;
        }

        public function open(string $command, ?string $directory = null): ?string
        {
            $this->opened[] = $command;

            if (preg_match("/echo \\\$\\\$ > '([^']+)'/", $command, $matches) === 1) {
                file_put_contents($matches[1], "4242\n");
            }

            return null;
        }

        public function close(?string $handle): void {}
    };
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
    bindWorkerDeps($this->dir);

    $context = new ServeContext(new ServeOptions);

    $result = app(StartScheduler::class)->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertNothingRan();
});

test('an enabled scheduler starts a tracked schedule:work process', function (): void {
    ProcessFaker::fake(['*' => Process::result(output: "4242\n")]);
    $ledger = bindWorkerDeps($this->dir, new SchedulerConfig(enabled: true, runIn: RunMode::Background));

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
    $ledger = bindWorkerDeps($this->dir, new SchedulerConfig(enabled: true));
    $ledger->record(new ProcessRecord(4242, 'scheduler', 'php artisan schedule:work', date(DATE_ATOM)));

    app(StartScheduler::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    ProcessFaker::assertDidntRun('*nohup*');
    Prompt::assertStrippedOutputContains('Scheduler already running');
});

test('horizon is skipped when the project does not require it', function (): void {
    Process::fake();
    bindWorkerDeps($this->dir, composer: ['require' => ['laravel/framework' => '^13.0']]);

    app(StartHorizon::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertNothingRan();
});

test('horizon starts when the project requires it', function (): void {
    ProcessFaker::fake(['*' => Process::result(output: "4242\n")]);
    $ledger = bindWorkerDeps($this->dir, new HorizonConfig(runIn: RunMode::Background), ['require' => ['laravel/horizon' => '^6.0']]);

    app(StartHorizon::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    ProcessFaker::assertRan('*nohup php artisan horizon*horizon.log*');
    expect($ledger->withLabel('horizon'))->toHaveCount(1);
    Prompt::assertStrippedOutputContains('Horizon started (PID 4242)');
});

test('horizon queues into the combined stream by default', function (): void {
    Process::fake();
    bindWorkerDeps($this->dir, composer: ['require' => ['laravel/horizon' => '^6.0']]);

    app(StartHorizon::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    $services = app(CombinedRunPlan::class)->services();

    expect($services)->toHaveCount(1)
        ->and($services[0]->label)->toBe('horizon')
        ->and($services[0]->command->toString())->toContain('php artisan horizon');
    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('Horizon will stream here as [horizon] once the boot completes.');
});

test('horizon opens a terminal window when configured', function (): void {
    Process::fake();
    $terminal = fakeServiceTerminal();
    $ledger = bindWorkerDeps($this->dir, new HorizonConfig(runIn: RunMode::Terminal), ['require' => ['laravel/horizon' => '^6.0']], $terminal);

    app(StartHorizon::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    expect($terminal->opened)->toHaveCount(1)
        ->and($terminal->opened[0])->toContain('php artisan horizon')
        ->and($ledger->withLabel('horizon'))->toHaveCount(1);
    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', $process->command), 'nohup'));
});

test('a combined worker falls back to the background without an interactive terminal', function (): void {
    ProcessFaker::fake(['*' => Process::result(output: "4242\n")]);
    $ledger = bindWorkerDeps($this->dir, composer: ['require' => ['laravel/horizon' => '^6.0']]);

    $context = new ServeContext(new ServeOptions(follow: false));
    app(StartHorizon::class)->handle($context, fn ($passed) => $passed);

    ProcessFaker::assertRan('*nohup php artisan horizon*horizon.log*');
    expect(app(CombinedRunPlan::class)->isEmpty())->toBeTrue()
        ->and($ledger->withLabel('horizon'))->toHaveCount(1);
    Prompt::assertStrippedOutputContains('no interactive terminal to stream into');
});

test('horizon can be disabled in configuration even when installed', function (): void {
    Process::fake();
    bindWorkerDeps($this->dir, new HorizonConfig(enabled: false), ['require' => ['laravel/horizon' => '^6.0']]);

    app(StartHorizon::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertNothingRan();
});

test('reverb starts when the project requires it', function (): void {
    ProcessFaker::fake(['*' => Process::result(output: "4242\n")]);
    $ledger = bindWorkerDeps($this->dir, new ReverbConfig(runIn: RunMode::Background), ['require' => ['laravel/reverb' => '^2.0']]);

    app(StartReverb::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    ProcessFaker::assertRan('*nohup php artisan reverb:start*reverb.log*');
    expect($ledger->withLabel('reverb'))->toHaveCount(1);
});

test('reverb opens a terminal window when configured', function (): void {
    Process::fake();
    $terminal = fakeServiceTerminal();
    $ledger = bindWorkerDeps($this->dir, new ReverbConfig(runIn: RunMode::Terminal), ['require' => ['laravel/reverb' => '^2.0']], $terminal);

    app(StartReverb::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    expect($terminal->opened)->toHaveCount(1)
        ->and($terminal->opened[0])->toContain('php artisan reverb:start')
        ->and($ledger->withLabel('reverb'))->toHaveCount(1);
    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', $process->command), 'nohup'));
});

test('reverb is skipped when the project does not require it', function (): void {
    Process::fake();
    bindWorkerDeps($this->dir, composer: ['require' => []]);

    app(StartReverb::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertNothingRan();
});
