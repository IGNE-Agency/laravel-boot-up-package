<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Data\CombinedService;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Enums\RegistrationSource;
use Igne\LaravelBootUp\Enums\StreamColor;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Process\NullTerminalLauncher;
use Igne\LaravelBootUp\Process\OutputMultiplexer;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Serve\BootCommandRegistry;
use Igne\LaravelBootUp\Serve\CombinedRunPlan;
use Igne\LaravelBootUp\Serve\Steps\StartRegisteredProcesses;
use Igne\LaravelBootUp\Serve\StreamOrder;
use Igne\LaravelBootUp\Services\Poller;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

/**
 * Call AFTER Process::fake() so the runner and reaper receive the faked
 * factory. Returns the fresh registry the step will read.
 */
function bindRegisteredDeps(string $dir, ?PackageManager $packageManager = null): BootCommandRegistry
{
    $registry = new BootCommandRegistry(runningInConsole: true, vendorPath: '/nonexistent/vendor');
    $ledger = new ProcessLedger($dir.'/processes.json');

    app()->instance(BootCommandRegistry::class, $registry);
    app()->instance(CombinedRunPlan::class, new CombinedRunPlan);
    app()->instance(PackageManagerSelector::class, new PackageManagerSelector(
        new FrontendConfig($packageManager),
        new PackageJson($dir.'/package.json'),
    ));
    app()->instance(ProcessLedger::class, $ledger);
    app()->instance(ProcessReaper::class, new ProcessReaper(app(Factory::class), $ledger, new Poller, new NullTerminalLauncher));
    app()->instance(ProcessRunner::class, new ProcessRunner(
        processes: app(Factory::class),
        ledger: $ledger,
        terminal: new NullTerminalLauncher,
        poller: new Poller,
        logDirectory: $dir.'/logs',
        runtimeDirectory: $dir.'/runtime',
    ));

    return $registry;
}

function runRegisteredStep(): void
{
    app(StartRegisteredProcesses::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);
}

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-registered-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    Prompt::fake();
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

test('a registration queues into the combined stream with its color and FORCE_COLOR', function (): void {
    Process::fake();
    $registry = bindRegisteredDeps($this->dir);
    $registry->register('stripe listen --forward-to http://localhost', source: RegistrationSource::Application)->orange();

    runRegisteredStep();

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('Registered command [stripe] will stream here as [stripe] once the boot completes.');

    $service = app(CombinedRunPlan::class)->services()[0];

    expect($service->label)->toBe('stripe')
        ->and($service->name)->toBe('stripe')
        ->and($service->color)->toBe(StreamColor::Orange)
        ->and($service->command->env)->toBe(['FORCE_COLOR' => '1'])
        ->and($service->command->tokens)->toBe(['stripe', 'listen', '--forward-to', 'http://localhost'])
        ->and($service->command->timeout)->toBeNull();
});

test('a background registration starts detached and lands in the ledger', function (): void {
    ProcessFaker::fake(['*' => Process::result(output: "4242\n")]);
    $registry = bindRegisteredDeps($this->dir);
    $registry->artisan('reverb:start --debug', 'reverb', RegistrationSource::Application)->inBackground();

    runRegisteredStep();

    ProcessFaker::assertRan('*nohup php artisan reverb:start --debug*reverb.log*');
    expect(app(ProcessLedger::class)->withLabel('reverb'))->toHaveCount(1);
    Prompt::assertStrippedOutputContains('Registered command [reverb] started (PID 4242)');
});

test('package-manager registrations resolve through the selected manager', function (): void {
    Process::fake();
    $registry = bindRegisteredDeps($this->dir, PackageManager::PNPM);
    $registry->packageManager('run dev', source: RegistrationSource::Application);
    $registry->packageManagerExec('vite --port 3000', source: RegistrationSource::Application);

    runRegisteredStep();

    $services = app(CombinedRunPlan::class)->services();

    expect($services[0]->command->tokens)->toBe(['pnpm', 'run', 'dev'])
        ->and($services[0]->name)->toBe('dev')
        ->and($services[1]->command->tokens)->toBe(['pnpm', 'exec', 'vite', '--port', '3000'])
        ->and($services[1]->name)->toBe('vite');
});

test('registrations join the built-ins in the ordered, colored combined stream', function (): void {
    Process::fake([
        '*queue:work*' => Process::describe()->output(['queue works'])->runsFor(iterations: 1),
        '*reverb:start*' => Process::describe()->output(['reverb up'])->runsFor(iterations: 1),
        '*stripe*' => Process::describe()->output(['stripe ready'])->runsFor(iterations: 1),
    ]);

    $registry = bindRegisteredDeps($this->dir);
    $registry->artisan('reverb:start --host=0.0.0.0', 'reverb', RegistrationSource::Application)->pink();
    $registry->register('stripe listen', source: RegistrationSource::Application)->orange()->after('queue');

    // Earlier pipeline steps queued the server tail and the queue worker.
    $plan = app(CombinedRunPlan::class);
    $plan->add(CombinedService::tail('artisan-serve', 'server', $this->dir.'/server.log'));
    $plan->add(CombinedService::process('queue-worker', 'queue', CommandLine::make('php artisan queue:work')->withTimeout(null)));

    runRegisteredStep();

    $ordered = app(StreamOrder::class)->apply($plan);

    // stripe lands right after queue; the reverb replacement inherits the
    // built-in's canonical slot even though it queued last.
    expect(array_map(fn (CombinedService $service): string => $service->name, $ordered->services()))
        ->toBe(['server', 'queue', 'stripe', 'reverb']);

    $written = [];
    $multiplexer = new OutputMultiplexer(
        app(Factory::class),
        app(ProcessLedger::class),
        pollIntervalMicroseconds: 0,
        write: function (string $text) use (&$written): void {
            $written[] = $text;
        },
    );

    $multiplexer->stream($ordered);
    $output = implode('', $written);

    expect($output)->toContain(terminal()->hex(StreamColor::Purple->value, str_pad('[queue]', 8)))
        ->and($output)->toContain(terminal()->hex(StreamColor::Orange->value, str_pad('[stripe]', 8)))
        ->and($output)->toContain(terminal()->hex(StreamColor::Pink->value, str_pad('[reverb]', 8)));
});

test('a filtered-out registration is skipped with a note', function (): void {
    Process::fake();
    $registry = bindRegisteredDeps($this->dir);
    $registry->register('stripe listen', source: RegistrationSource::Application);
    $registry->except('stripe');

    runRegisteredStep();

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('Registered command [stripe] skipped (BootCommands only/except).');
    expect(app(CombinedRunPlan::class)->isEmpty())->toBeTrue();
});
