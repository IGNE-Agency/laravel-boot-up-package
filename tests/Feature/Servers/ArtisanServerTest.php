<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\ServersConfig;
use Igne\LaravelBootUp\Contracts\ProvidesDatabase;
use Igne\LaravelBootUp\Contracts\RequiresTools;
use Igne\LaravelBootUp\Contracts\RewritesCommands;
use Igne\LaravelBootUp\Contracts\WarnsBeforeStop;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Process\NullTerminalLauncher;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Serve\WorkerLauncher;
use Igne\LaravelBootUp\Servers\Artisan\ArtisanServer;
use Igne\LaravelBootUp\Servers\CommandRewriter;
use Igne\LaravelBootUp\Services\Poller;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    Prompt::fake();
    $this->workDir = sys_get_temp_dir().'/boot-up-artisan-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);
    $this->ledger = new ProcessLedger($this->workDir.'/processes.json');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

function artisanServer(ProcessLedger $ledger, string $workDir, ?ServersConfig $config = null): ArtisanServer
{
    $runner = new ProcessRunner(
        processes: app(Factory::class),
        ledger: $ledger,
        terminal: new NullTerminalLauncher,
        poller: new Poller,
        logDirectory: $workDir.'/logs',
        runtimeDirectory: $workDir.'/runtime',
    );

    $reaper = new ProcessReaper(app(Factory::class), $ledger, new Poller, new NullTerminalLauncher);

    return new ArtisanServer(
        $runner,
        new WorkerLauncher($runner, new CommandRewriter, $ledger, $reaper),
        $config ?? new ServersConfig,
    );
}

test('start spawns a tracked detached php artisan serve', function (): void {
    ProcessFaker::fake(['*' => Process::result(output: "4242\n")]);

    artisanServer($this->ledger, $this->workDir)->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertRan('*nohup php artisan serve*artisan-serve.log*echo $!*');

    $records = $this->ledger->withLabel('artisan-serve');

    expect($records)->toHaveCount(1)
        ->and($records->first()->pid)->toBe(4242)
        ->and($records->first()->command)->toBe('php artisan serve --host=127.0.0.1 --port=8000');
    Prompt::assertStrippedOutputContains('php artisan serve started (PID 4242).');
});

test('a second start is skipped while the tracked process is alive', function (): void {
    ProcessFaker::fake([
        'kill -0 4242' => Process::result(),
        'ps -p 4242*' => Process::result(output: "php artisan serve --host=127.0.0.1 --port=8000\n"),
        '*' => Process::result(output: "4242\n"),
    ]);

    $server = artisanServer($this->ledger, $this->workDir);
    $context = new ServeContext(new ServeOptions);

    $server->start($context);
    $server->start($context);

    ProcessFaker::assertRanTimes('*nohup php artisan serve*', 1);
    expect($this->ledger->withLabel('artisan-serve'))->toHaveCount(1);
    Prompt::assertStrippedOutputContains('php artisan serve is already running.');
});

test('isRunning is false when the tracked pid is dead', function (): void {
    $this->ledger->record(new ProcessRecord(4242, 'artisan-serve', 'php artisan serve', date(DATE_ATOM)));
    ProcessFaker::fake(['kill -0 4242' => Process::result(exitCode: 1)]);

    expect(artisanServer($this->ledger, $this->workDir)->isRunning())->toBeFalse();
});

test('isRunning is false when nothing was ever started', function (): void {
    ProcessFaker::fake();

    expect(artisanServer($this->ledger, $this->workDir)->isRunning())->toBeFalse();
    Process::assertNothingRan();
});

test('stop forgets records whose process is already gone', function (): void {
    $this->ledger->record(new ProcessRecord(4242, 'artisan-serve', 'php artisan serve', date(DATE_ATOM)));
    ProcessFaker::fake(['kill -0 4242' => Process::result(exitCode: 1)]);

    artisanServer($this->ledger, $this->workDir)->stop();

    expect($this->ledger->withLabel('artisan-serve'))->toHaveCount(0);
});

test('stop terminates a live tracked process', function (): void {
    $this->ledger->record(new ProcessRecord(4242, 'artisan-serve', 'php artisan serve', date(DATE_ATOM)));
    ProcessFaker::fake([
        'kill -0 4242' => Process::sequence()
            ->push(Process::result())
            ->push(Process::result())
            ->push(Process::result(exitCode: 1)),
        'ps -p 4242*' => Process::result(output: "php artisan serve\n"),
    ]);

    artisanServer($this->ledger, $this->workDir)->stop();

    ProcessFaker::assertRan('kill -TERM 4242');
    expect($this->ledger->withLabel('artisan-serve'))->toHaveCount(0);
});

test('url derives from the configured bind address, never app.url', function (): void {
    ProcessFaker::fake();

    config()->set('app.url', 'http://example.test');
    expect(artisanServer($this->ledger, $this->workDir)->url())->toBe('http://127.0.0.1:8000');

    $custom = artisanServer($this->ledger, $this->workDir, new ServersConfig(artisanHost: '0.0.0.0', artisanPort: 8080));
    expect($custom->url())->toBe('http://0.0.0.0:8080');
});

test('start passes the configured host and port to artisan serve', function (): void {
    ProcessFaker::fake(['*' => Process::result(output: "4242\n")]);

    artisanServer($this->ledger, $this->workDir, new ServersConfig(artisanHost: '0.0.0.0', artisanPort: 8080))
        ->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertRan('*php artisan serve --host=0.0.0.0 --port=8080*');
});

test('identity, with no optional capabilities', function (): void {
    ProcessFaker::fake();
    $server = artisanServer($this->ledger, $this->workDir);

    expect($server->key())->toBe('laravel')
        ->and($server->label())->toBe('Laravel (php artisan serve)')
        ->and($server)->not->toBeInstanceOf(RequiresTools::class)
        ->and($server)->not->toBeInstanceOf(RewritesCommands::class)
        ->and($server)->not->toBeInstanceOf(ProvidesDatabase::class)
        ->and($server)->not->toBeInstanceOf(WarnsBeforeStop::class);
});
