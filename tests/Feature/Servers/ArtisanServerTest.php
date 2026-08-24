<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\ArtisanServeConfig;
use Igne\LaravelBootUp\Contracts\ProvidesDatabase;
use Igne\LaravelBootUp\Contracts\RequiresTools;
use Igne\LaravelBootUp\Contracts\RewritesCommands;
use Igne\LaravelBootUp\Contracts\WarnsBeforeStop;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Servers\Artisan\ArtisanServer;
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

function artisanServer(ProcessLedger $ledger, string $workDir, ?ArtisanServeConfig $config = null): ArtisanServer
{
    return new ArtisanServer(
        new ProcessReaper(app(Factory::class), $ledger, new Poller),
        $config ?? new ArtisanServeConfig,
    );
}

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

    $custom = artisanServer($this->ledger, $this->workDir, new ArtisanServeConfig(host: '0.0.0.0', port: 8080));
    expect($custom->url())->toBe('http://0.0.0.0:8080');
});

test('identity, with no optional capabilities', function (): void {
    ProcessFaker::fake();
    $server = artisanServer($this->ledger, $this->workDir);

    expect($server->key())->toBe('artisan')
        ->and($server->label())->toBe('Laravel (php artisan serve)')
        ->and($server)->not->toBeInstanceOf(RequiresTools::class)
        ->and($server)->not->toBeInstanceOf(RewritesCommands::class)
        ->and($server)->not->toBeInstanceOf(ProvidesDatabase::class)
        ->and($server)->not->toBeInstanceOf(WarnsBeforeStop::class);
});

test('devProcess runs artisan serve on the configured address', function (): void {
    ProcessFaker::fake();
    $server = artisanServer($this->ledger, $this->workDir, new ArtisanServeConfig(host: '0.0.0.0', port: 9000));

    $command = $server->devProcess(new BootContext(new BootOptions));

    expect($command?->toString())->toBe('php artisan serve --host=0.0.0.0 --port=9000')
        ->and($command?->timeout)->toBeNull();
});

test('devProcess carries no server when one is already serving this project', function (): void {
    ProcessFaker::fake(['*' => Process::result(output: "4242\n")]);
    $this->ledger->record(new ProcessRecord(4242, 'artisan-serve', 'php artisan serve', date(DATE_ATOM)));
    $server = artisanServer($this->ledger, $this->workDir);

    expect($server->devProcess(new BootContext(new BootOptions)))->toBeNull();
});

test('start runs nothing — the serve command is a dev process', function (): void {
    ProcessFaker::fake(['*' => Process::result(output: "4242\n")]);

    artisanServer($this->ledger, $this->workDir)->start(new BootContext(new BootOptions));

    expect($this->ledger->withLabel('artisan-serve'))->toBeEmpty();
    ProcessFaker::assertRanTimes('*nohup php artisan serve*', 0);
    Prompt::assertStrippedOutputContains('php artisan serve runs with the dev processes.');
});

test('start says so when a tracked serve is already alive', function (): void {
    $this->ledger->record(new ProcessRecord(4242, 'artisan-serve', 'php artisan serve', date(DATE_ATOM)));
    ProcessFaker::fake([
        'kill -0 4242' => Process::result(),
        'ps -p 4242*' => Process::result(output: "php artisan serve\n"),
    ]);

    artisanServer($this->ledger, $this->workDir)->start(new BootContext(new BootOptions));

    Prompt::assertStrippedOutputContains('php artisan serve is already running.');
});
