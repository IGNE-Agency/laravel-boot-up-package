<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Process\ProcessLedger;
use Igne\LaravelBootstrap\Process\ProcessReaper;
use Igne\LaravelBootstrap\Process\ProcessRecord;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\Terminal\NullTerminal;
use Igne\LaravelBootstrap\Serve\ServeContext;
use Igne\LaravelBootstrap\Serve\ServeOptions;
use Igne\LaravelBootstrap\Servers\Artisan\ArtisanServer;
use Igne\LaravelBootstrap\Support\Poller;
use Igne\LaravelBootstrap\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    Prompt::fake();
    $this->workDir = sys_get_temp_dir().'/bootstrap-artisan-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);
    $this->ledger = new ProcessLedger($this->workDir.'/processes.json');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

function artisanServer(ProcessLedger $ledger, string $workDir): ArtisanServer
{
    $runner = new ProcessRunner(
        processes: app(Factory::class),
        ledger: $ledger,
        terminal: new NullTerminal,
        poller: new Poller,
        logDirectory: $workDir.'/logs',
        runtimeDirectory: $workDir.'/runtime',
    );

    return new ArtisanServer(
        $runner,
        $ledger,
        new ProcessReaper(app(Factory::class), $ledger, new Poller),
        app('config'),
    );
}

test('start spawns a tracked detached php artisan serve', function (): void {
    ProcessFaker::fake(['*' => Process::result(output: "4242\n")]);

    artisanServer($this->ledger, $this->workDir)->start(new ServeContext(new ServeOptions));

    ProcessFaker::assertRan('*nohup php artisan serve*artisan-serve.log*echo $!*');

    $records = $this->ledger->withLabel('artisan-serve');

    expect($records)->toHaveCount(1)
        ->and($records->first()->pid)->toBe(4242)
        ->and($records->first()->command)->toBe('php artisan serve');
    Prompt::assertStrippedOutputContains('php artisan serve started (PID 4242).');
});

test('a second start is skipped while the tracked process is alive', function (): void {
    ProcessFaker::fake([
        'kill -0 4242' => Process::result(),
        'ps -p 4242*' => Process::result(output: "php artisan serve\n"),
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

    ProcessFaker::assertRan('pkill -TERM -P 4242');
    ProcessFaker::assertRan('kill -TERM 4242');
    expect($this->ledger->withLabel('artisan-serve'))->toHaveCount(0);
});

test('url falls back to the artisan serve default when app.url is empty', function (): void {
    ProcessFaker::fake();
    $server = artisanServer($this->ledger, $this->workDir);

    config()->set('app.url', '');
    expect($server->url())->toBe('http://127.0.0.1:8000');

    config()->set('app.url', 'http://example.test');
    expect($server->url())->toBe('http://example.test');
});

test('identity, tools and rewrites', function (): void {
    ProcessFaker::fake();
    $server = artisanServer($this->ledger, $this->workDir);
    $rewrites = $server->commandRewrites();

    expect($server->key())->toBe('laravel')
        ->and($server->label())->toBe('Laravel (php artisan serve)')
        ->and($server->requiredTools())->toBe([])
        ->and($rewrites->replaces)->toBe([])
        ->and($rewrites->prefixes)->toBe([])
        ->and($rewrites->prefix)->toBeNull();
});
