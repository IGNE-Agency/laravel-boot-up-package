<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Boot\Steps\AnnounceApplication;
use Igne\LaravelBootUp\Console\DevCommand;
use Igne\LaravelBootUp\Environment\EnvFile;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Servers\ActiveServerStore;
use Igne\LaravelBootUp\Servers\Steps\StartServer;
use Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\CapturingDevCommand;
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

    // Every success path now reaches the handoff, and the handoff launches a
    // real terminal UI over npx. Capture it instead.
    $this->command = new CapturingDevCommand;
    app()->instance(DevCommand::class, $this->command);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

test('boots the artisan driver end to end: persisted state, serve handed to the dev processes', function (): void {
    ProcessFaker::fake();

    $this->artisan('dev', ['server' => 'artisan'])->assertSuccessful();

    // Nothing is spawned behind the boot any more: the serve command is a
    // dev process, so it arrives at the handoff instead.
    ProcessFaker::assertDidntRun('sh -c nohup*');
    expect($this->ledger->withLabel('artisan-serve'))->toBeEmpty()
        ->and(array_column($this->command->handedOver, 'command'))
        ->toContain('php artisan serve --host=127.0.0.1 --port=8000');

    $active = $this->store->current();
    expect($active)->not->toBeNull()
        ->and($active->key)->toBe('artisan')
        ->and($active->startedByUs)->toBeTrue()
        ->and($active->setupPid)->toBe((int) getmypid());
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

test('a stdout that is not a terminal still hands off, rather than silently detaching', function (): void {
    // The test runner's stdout is a pipe. dev used to read that as "no
    // terminal to stream into" and quietly start everything in the
    // background, so the terminal UI never appeared and nothing said why.
    // Non-TTY is upstream's business: it renders inline.
    ProcessFaker::fake();
    config()->set('queue.default', 'database');

    $this->artisan('dev', ['server' => 'artisan'])->assertSuccessful();

    expect($this->command->handoffs)->toBe(1);
    ProcessFaker::assertDidntRun('sh -c nohup*');
    expect($this->ledger->all())->toBeEmpty();
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

test('a foreground boot hands the dev processes to the framework', function (): void {
    ProcessFaker::fake();
    config()->set('queue.default', 'database');
    config()->set('boot-up.shutdown.prompt_stop_server', false);

    $command = $this->command;

    $this->artisan('dev', ['server' => 'artisan'])->assertSuccessful();

    expect($command->handoffs)->toBe(1)
        ->and(array_column($command->handedOver, 'name'))->toBe(['server', 'queue'])
        ->and(array_column($command->handedOver, 'command'))->toBe([
            'php artisan serve --host=127.0.0.1 --port=8000',
            'php artisan queue:work database',
        ]);

    // The server runs as a dev process now, so nothing was launched behind it,
    // and nothing tears down: quitting the terminal UI leaves the project set
    // up, which is what app:down is for.
    ProcessFaker::assertDidntRun('sh -c nohup*');
    expect($this->store->current())->not->toBeNull();
});

test('php artisan dev resolves to boot-up\'s command, not the framework\'s', function (): void {
    $resolved = Illuminate\Support\Facades\Artisan::all()['dev'];

    expect($resolved)->toBeInstanceOf(DevCommand::class)
        ->and($resolved)->toBeInstanceOf(Illuminate\Foundation\Console\DevCommand::class)
        ->and($resolved->getDescription())->toContain('Boot everything the application needs');
});

test('the framework binding resolves to the same command', function (): void {
    expect(app(Illuminate\Foundation\Console\DevCommand::class))
        ->toBeInstanceOf(DevCommand::class);
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
