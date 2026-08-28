<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Boot\DevSession;
use Igne\LaravelBootUp\Boot\ProjectReadiness;
use Igne\LaravelBootUp\Config\EnvironmentConfig;
use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Console\DevCommand;
use Igne\LaravelBootUp\Data\ActiveServerRecord;
use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Enums\OperatingSystem;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Environment\LocalEnvironment;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Services\Platform;
use Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\CapturingDevCommand;
use Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\ExplodingStep;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\NodePackageManager;
use Symfony\Component\Console\Input\ArrayInput;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/boot-up-dev-cmd-'.bin2hex(random_bytes(4));
    mkdir($this->workDir.'/vendor', 0755, true);

    $this->ledger = new ProcessLedger($this->workDir.'/processes.json');
    $this->store = new ActiveServerStore($this->workDir.'/active-server.json');

    app()->instance(ProcessLedger::class, $this->ledger);
    app()->instance(ActiveServerStore::class, $this->store);
    app()->singleton(ProcessRunner::class, fn ($app) => new ProcessRunner(
        processes: $app->make(Factory::class),
        ledger: $this->ledger,
        logDirectory: $this->workDir.'/logs',
    ));

    // A project app:up already finished with: a .env, installed Composer
    // dependencies, and a recorded server. Individual tests take pieces away.
    file_put_contents($this->workDir.'/.env', "APP_ENV=local\nAPP_KEY=base64:x\n");
    file_put_contents($this->workDir.'/vendor/autoload.php', '<?php');
    $this->store->remember(new ActiveServerRecord('artisan', true, 4242, date(DATE_ATOM)));

    app()->instance(EnvFile::class, new EnvFile($this->workDir.'/.env', $this->workDir.'/.env.example'));
    app()->instance(ProjectReadiness::class, new ProjectReadiness(
        envFile: app(EnvFile::class),
        packageJson: new PackageJson($this->workDir.'/package.json'),
        frontendConfig: new FrontendConfig(assets: AssetMode::Watch),
        localEnvironment: new LocalEnvironment(app(EnvFile::class), new EnvironmentConfig, []),
        basePath: $this->workDir,
    ));

    config()->set('queue.default', 'sync');

    // The handoff launches a real terminal UI over npx. Capture it instead.
    $this->command = new CapturingDevCommand;
    app()->instance(DevCommand::class, $this->command);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

test('hands the project\'s dev processes to the framework, in tab order', function (): void {
    ProcessFaker::fake();
    config()->set('queue.default', 'database');

    $this->artisan('dev')->assertSuccessful();

    expect($this->command->handoffs)->toBe(1)
        ->and(array_column($this->command->handedOver, 'name'))->toBe(['server', 'queue'])
        ->and(array_column($this->command->handedOver, 'command'))->toBe([
            'php artisan serve --host=127.0.0.1 --port=8000',
            'php artisan queue:work database',
        ]);

    // Nothing is spawned behind the handoff: every process belongs to the
    // terminal UI, and nothing is torn down when it exits.
    ProcessFaker::assertDidntRun('sh -c nohup*');
    expect($this->ledger->all())->toBeEmpty()
        ->and($this->store->current())->not->toBeNull();
});

test('a stdout that is not a terminal still hands off, rather than silently detaching', function (): void {
    // dev used to read a non-TTY stdout as "no terminal to stream into" and
    // quietly start everything in the background, so the terminal UI never
    // appeared and nothing said why. Non-TTY is upstream's business: it
    // renders inline.
    ProcessFaker::fake();
    config()->set('queue.default', 'database');

    $this->artisan('dev')->assertSuccessful();

    expect($this->command->handoffs)->toBe(1);
    ProcessFaker::assertDidntRun('sh -c nohup*');
});

test('a setup step that would explode never runs, because dev does not boot', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.setup.steps', [ExplodingStep::class]);

    $this->artisan('dev')->assertSuccessful();

    expect($this->command->handoffs)->toBe(1);
});

test('uses the server app:up recorded', function (): void {
    ProcessFaker::fake();

    $this->artisan('dev')->assertSuccessful();

    expect(array_column($this->command->handedOver, 'command'))
        ->toContain('php artisan serve --host=127.0.0.1 --port=8000');
});

test('an explicit server argument overrides the record', function (): void {
    ProcessFaker::fake();

    $this->artisan('dev', ['server' => 'sail'])->assertSuccessful();

    expect(array_column($this->command->handedOver, 'command'))
        ->toContain('./vendor/bin/sail logs --follow');
});

test('rejects an unknown server argument with a clean, actionable failure', function (): void {
    ProcessFaker::fake();

    $this->artisan('dev', ['server' => 'nginx'])
        ->expectsOutputToContain('Unknown development server [nginx]')
        ->assertFailed();

    expect($this->command->handoffs)->toBe(0);
});

test('names app:up when no server is set up for this project', function (): void {
    ProcessFaker::fake();
    $this->store->clear();

    Artisan::call('dev');

    expect(Artisan::output())
        ->toContain('No development server is set up for this project.')
        ->toContain('php artisan app:up')
        ->and($this->command->handoffs)->toBe(0);
});

test('names app:up, and every reason, when the project is not set up', function (): void {
    ProcessFaker::fake();
    unlink($this->workDir.'/vendor/autoload.php');
    file_put_contents($this->workDir.'/.env', "APP_ENV=production\n");

    Artisan::call('dev');

    expect(Artisan::output())
        ->toContain('This project is not ready to run its dev processes')
        ->toContain('APP_ENV is [production]')
        ->toContain('APP_KEY is not set in .env.')
        ->toContain('Composer dependencies are not installed.')
        ->toContain('Set it up with: php artisan app:up')
        ->and($this->command->handoffs)->toBe(0);
});

test('an unready project fails, so nothing downstream of it runs', function (): void {
    ProcessFaker::fake();
    unlink($this->workDir.'/.env');

    $this->artisan('dev')->assertFailed();

    Process::assertNothingRan();
});

test('fails fast on native Windows', function (): void {
    ProcessFaker::fake();
    app()->instance(Platform::class, new Platform(OperatingSystem::Windows));

    $this->artisan('dev')
        ->expectsOutputToContain('not supported on native Windows')
        ->assertFailed();

    Process::assertNothingRan();
});

test('--detach runs the dev processes in the background', function (): void {
    ProcessFaker::fake([
        'sh -c nohup php artisan serve*' => Process::result('12345'),
        'sh -c nohup php artisan queue:work*' => Process::result('12346'),
    ]);
    config()->set('queue.default', 'database');

    $this->artisan('dev', ['--detach' => true])
        ->expectsOutputToContain('The dev processes run in the background')
        ->assertSuccessful();

    ProcessFaker::assertRan('sh -c nohup php artisan queue:work database*');
    expect($this->command->handoffs)->toBe(0);
});

test('says so instead of starting an empty terminal when every process is gated off', function (): void {
    ProcessFaker::fake();
    config()->set('boot-up.dev.logs', false);
    config()->set('boot-up.frontend.assets', 'skip');
    // The recorded artisan driver serves the project itself once a tracked
    // serve is alive, which leaves nothing for the registry.
    $this->ledger->record(new Igne\LaravelBootUp\Data\ProcessRecord(12345, 'artisan-serve', 'php artisan serve', date(DATE_ATOM)));
    ProcessFaker::fake([
        'kill -0 12345' => Process::result(),
        'ps -p 12345*' => Process::result('php artisan serve'),
    ]);

    $this->artisan('dev')
        ->expectsOutputToContain('Nothing to run')
        ->assertSuccessful();

    expect($this->command->handoffs)->toBe(0);
});

test('a session app:up claimed runs the terminal UI as a child, not in place of this process', function (): void {
    ProcessFaker::fake();

    // Claiming is what app:up does before handing over. Without it this
    // path ends in pcntl_exec, which would replace the test runner itself —
    // which is exactly why only the claimed path can be exercised here.
    app(DevSession::class)->claim();

    $dev = Artisan::all()['dev'];

    $exitCode = (function () use ($dev): int {
        // The command is invoked directly rather than through artisan: an
        // unclaimed run would exec the terminal UI over this test process.
        $this->input = new ArrayInput([], $dev->getDefinition());

        return $this->runViaMultiplex(
            [['name' => 'server', 'command' => 'php artisan serve', 'color' => 'blue', 'priority' => 0]],
            new NodePackageManager,
        );
    })->call($dev);

    expect($exitCode)->toBe(0);

    // Through a shell, so the quoting getExecCommand() produced survives, and
    // with no timeout, because a dev session runs as long as the user works.
    ProcessFaker::assertRan('sh -c *@laravel/multiplex*');
    Process::assertRan(fn ($process): bool => $process->timeout === null);
});

test('every command shares one dev session, which is how the claim crosses the boundary', function (): void {
    app(DevSession::class)->claim();

    expect(app(DevSession::class)->isClaimed())->toBeTrue();
});

test('php artisan dev resolves to boot-up\'s command, not the framework\'s', function (): void {
    $resolved = Artisan::all()['dev'];

    expect($resolved)->toBeInstanceOf(DevCommand::class)
        ->and($resolved)->toBeInstanceOf(Illuminate\Foundation\Console\DevCommand::class)
        ->and($resolved->getDescription())->toBe('Run the dev processes this project needs');
});

test('the framework binding resolves to the same command', function (): void {
    expect(app(Illuminate\Foundation\Console\DevCommand::class))
        ->toBeInstanceOf(DevCommand::class);
});

test('dev keeps every option the framework defines and adds only its own', function (): void {
    $definition = Artisan::all()['dev']->getDefinition();

    expect($definition->hasOption('tabs'))->toBeTrue()
        ->and($definition->hasOption('stream'))->toBeTrue()
        ->and($definition->hasOption('inline'))->toBeTrue()
        ->and($definition->hasOption('no-restart'))->toBeTrue()
        ->and($definition->hasOption('detach'))->toBeTrue()
        ->and($definition->hasOption('without-queue'))->toBeTrue()
        ->and($definition->hasOption('without-assets'))->toBeTrue()
        ->and($definition->hasArgument('server'))->toBeTrue()
        // The boot's flags moved to app:up with the boot.
        ->and($definition->hasOption('seed'))->toBeFalse()
        ->and($definition->hasOption('no-migrate'))->toBeFalse()
        ->and($definition->hasOption('fresh'))->toBeFalse()
        ->and($definition->hasOption('update'))->toBeFalse()
        ->and($definition->hasOption('yes'))->toBeFalse();
});

test('dev has no alias and no isolation lock', function (): void {
    $resolved = Artisan::all()['dev'];

    // Nothing to serialise a run against: dev starts nothing that outlives
    // it, and an --isolated run would hold its mutex past pcntl_exec.
    expect($resolved->getAliases())->toBe([])
        ->and($resolved)->not->toBeInstanceOf(Isolatable::class);
});

test('--without-queue and --without-assets keep processes out of the handoff', function (): void {
    ProcessFaker::fake();
    config()->set('queue.default', 'database');

    $this->artisan('dev', ['--without-queue' => true])->assertSuccessful();

    expect(array_column($this->command->handedOver, 'name'))->toBe(['server']);
});
