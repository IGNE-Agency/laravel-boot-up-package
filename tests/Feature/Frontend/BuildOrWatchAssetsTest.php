<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Data\ProcessRecord;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Enums\RunMode;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\Steps\BuildOrWatchAssets;
use Igne\LaravelBootUp\Process\NullTerminalLauncher;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessReaper;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Services\Poller;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

/**
 * Call AFTER Process::fake() so the runner and reaper receive the faked factory.
 */
function bindAssetServices(string $dir, AssetMode $assets = AssetMode::Watch, RunMode $watchIn = RunMode::Background): ProcessLedger
{
    $ledger = new ProcessLedger($dir.'/processes.json');

    app()->instance(PackageJson::class, new PackageJson($dir.'/package.json'));
    app()->instance(FrontendConfig::class, new FrontendConfig(PackageManager::BUN, $assets, $watchIn));
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

    return $ledger;
}

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-frontend-assets-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);

    Prompt::fake();
});

afterEach(function (): void {
    if (is_dir($this->dir)) {
        exec('rm -rf '.escapeshellarg($this->dir));
    }
});

test('skips with a note when assets are disabled by flag', function (): void {
    Process::fake();
    bindAssetServices($this->dir);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"dev":"vite"}}');

    $context = new ServeContext(new ServeOptions(withAssets: false));

    $result = app(BuildOrWatchAssets::class)->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('--without-assets');
});

test('skips with a note when the configured mode is skip', function (): void {
    Process::fake();
    bindAssetServices($this->dir, assets: AssetMode::Skip);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"dev":"vite"}}');

    app(BuildOrWatchAssets::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('disabled in configuration');
});

test('skips with a note when no package.json exists', function (): void {
    Process::fake();
    bindAssetServices($this->dir);

    app(BuildOrWatchAssets::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('No package.json found');
});

test('build mode runs the build script synchronously', function (): void {
    Process::fake(['*' => Process::result()]);
    bindAssetServices($this->dir, assets: AssetMode::Build);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"build":"vite build"}}');

    app(BuildOrWatchAssets::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertRan(fn ($process): bool => implode(' ', $process->command) === 'bun run build');
});

test('build mode skips with a note when package.json has no build script', function (): void {
    Process::fake();
    bindAssetServices($this->dir, assets: AssetMode::Build);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"dev":"vite"}}');

    app(BuildOrWatchAssets::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains("no 'build' script");
});

test('watch mode skips with a note when package.json has no dev script', function (): void {
    Process::fake();
    bindAssetServices($this->dir);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"build":"vite build"}}');

    app(BuildOrWatchAssets::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains("no 'dev' script");
});

test('watch mode spawns a tracked assets-watch background process without a timeout', function (): void {
    Process::fake(['*' => Process::result(output: "77\n")]);
    $ledger = bindAssetServices($this->dir);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"dev":"vite"}}');

    app(BuildOrWatchAssets::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertRan(function ($process): bool {
        $command = implode(' ', $process->command);

        return str_contains($command, 'nohup bun run dev')
            && str_contains($command, 'assets-watch.log')
            && $process->timeout === null;
    });

    $records = $ledger->withLabel('assets-watch');

    expect($records)->toHaveCount(1)
        ->and($records->first()->pid)->toBe(77);
    Prompt::assertStrippedOutputContains('assets-watch.log');
});

test('a second run with a live assets-watch record skips spawning', function (): void {
    Process::fake([
        "'kill'*" => Process::result(),
        "'ps'*" => Process::result(output: "bun run dev\n"),
        '*' => Process::result(output: "88\n"),
    ]);
    $ledger = bindAssetServices($this->dir);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"dev":"vite"}}');

    $ledger->record(new ProcessRecord(pid: 77, label: 'assets-watch', command: 'bun run dev', startedAt: date(DATE_ATOM)));

    app(BuildOrWatchAssets::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', $process->command), 'nohup'));

    expect($ledger->withLabel('assets-watch'))->toHaveCount(1);
    Prompt::assertStrippedOutputContains('already running');
});
