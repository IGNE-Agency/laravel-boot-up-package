<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Boot\Browser;
use Igne\LaravelBootUp\Boot\DeferredBrowser;
use Igne\LaravelBootUp\Config\SetupConfig;
use Igne\LaravelBootUp\Console\OpenBrowserCommand;
use Igne\LaravelBootUp\Data\BootContext;
use Igne\LaravelBootUp\Data\BootOptions;
use Igne\LaravelBootUp\Data\CommandLine;
use Igne\LaravelBootUp\Enums\OperatingSystem;
use Igne\LaravelBootUp\Frontend\ViteHotFile;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Services\Platform;
use Igne\LaravelBootUp\Tests\Feature\Boot\Fixtures\RecordingServer;
use Igne\LaravelBootUp\Tests\Feature\Servers\Fixtures\ProcessFaker;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/boot-up-deferred-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);

    $this->ledger = new ProcessLedger($this->workDir.'/processes.json');
    $this->hot = $this->workDir.'/hot';

    // A PID has to come back or start() throws, which is its own test below.
    ProcessFaker::fake(['sh -c nohup*' => Process::result(output: "4242\n")]);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

function deferredBrowser(ProcessLedger $ledger, string $hot, bool $openBrowser = true): DeferredBrowser
{
    $runner = new ProcessRunner(
        processes: app(Factory::class),
        ledger: $ledger,
        logDirectory: dirname($hot).'/logs',
    );

    return new DeferredBrowser(
        runner: $runner,
        browser: new Browser($runner, new Platform(OperatingSystem::Darwin)),
        hotFile: new ViteHotFile($hot),
        config: new SetupConfig(openBrowser: $openBrowser),
        artisanPath: '/project/artisan',
    );
}

function bootedContext(): BootContext
{
    return new BootContext(new BootOptions, new RecordingServer);
}

test('opens straight away when nothing the page needs starts after the boot', function (): void {
    // Herd serving prebuilt assets: the URL already answers, so waiting would
    // be a background process spawned to discover that immediately.
    deferredBrowser($this->ledger, $this->hot)->open(bootedContext(), ['queue', 'logs']);

    ProcessFaker::assertRan('open http://double.test');
    ProcessFaker::assertDidntRun('sh -c nohup*');
    expect($this->ledger->isEmpty())->toBeTrue();
});

test('waits in the background when a Vite watcher is going to run', function (): void {
    Prompt::fake();

    deferredBrowser($this->ledger, $this->hot)->open(bootedContext(), ['vite', 'logs']);

    // The waiter, not the browser: nothing is opened by this process.
    ProcessFaker::assertDidntRun('open http://double.test');
    ProcessFaker::assertRan('sh -c nohup*'.OpenBrowserCommand::NAME.' http://double.test --vite*browser.log*');

    expect($this->ledger->withLabel('browser'))->toHaveCount(1)
        ->and($this->ledger->withLabel('browser')->first()->pid)->toBe(4242);
});

test('waits without the Vite flag when only the server starts later', function (): void {
    Prompt::fake();

    // The artisan driver: php artisan serve is itself a dev process, so the
    // announced URL refuses connections until the dev terminal is up.
    deferredBrowser($this->ledger, $this->hot)->open(bootedContext(), ['server', 'queue']);

    ProcessFaker::assertRan('sh -c nohup*'.OpenBrowserCommand::NAME.' http://double.test *');
    ProcessFaker::assertDidntRun('sh -c nohup*--vite*');
});

test('runs the waiter on the host, with the interpreter that booted the project', function (): void {
    Prompt::fake();

    deferredBrowser($this->ledger, $this->hot)->open(bootedContext(), ['vite']);

    // Not `php artisan`, and never rewritten for the server: under Sail that
    // would run the wait inside the container, where there is no browser.
    // Rendered through CommandLine because a Herd interpreter lives under
    // "Application Support" — an unquoted path would split at the space.
    $interpreter = CommandLine::make([PHP_BINARY])->toString();

    ProcessFaker::assertRan('sh -c nohup '.$interpreter.' /project/artisan '.OpenBrowserCommand::NAME.'*');
    ProcessFaker::assertDidntRun('*sail*');
});

test('clears a hot file a crashed run left behind before waiting for a fresh one', function (): void {
    Prompt::fake();
    file_put_contents($this->hot, 'http://[::1]:5173');

    deferredBrowser($this->ledger, $this->hot)->open(bootedContext(), ['vite']);

    expect(is_file($this->hot))->toBeFalse();
});

test('leaves a hot file alone when this run brings no watcher of its own', function (): void {
    Prompt::fake();
    file_put_contents($this->hot, 'http://[::1]:5173');

    // Someone may be running Vite by hand; a marker we are not replacing is
    // not ours to delete.
    deferredBrowser($this->ledger, $this->hot)->open(bootedContext(), ['server']);

    expect(is_file($this->hot))->toBeTrue();
});

test('a browser that cannot be scheduled warns instead of failing the boot', function (): void {
    Prompt::fake();
    ProcessFaker::fake(['sh -c nohup*' => Process::result(output: '')]);

    deferredBrowser($this->ledger, $this->hot)->open(bootedContext(), ['vite']);

    Prompt::assertStrippedOutputContains('Could not schedule the browser');
    Prompt::assertStrippedOutputContains('open http://double.test yourself');
});

test('does nothing at all when open_browser is off', function (): void {
    deferredBrowser($this->ledger, $this->hot, openBrowser: false)->open(bootedContext(), ['vite']);

    ProcessFaker::assertDidntRun('sh -c nohup*');
    ProcessFaker::assertDidntRun('open*');
});

test('a run with no server has no URL to open', function (): void {
    deferredBrowser($this->ledger, $this->hot)->open(new BootContext(new BootOptions), ['vite']);

    ProcessFaker::assertDidntRun('sh -c nohup*');
    ProcessFaker::assertDidntRun('open*');
});
