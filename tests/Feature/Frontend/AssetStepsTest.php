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
use Igne\LaravelBootUp\Frontend\Steps\BuildAssets;
use Igne\LaravelBootUp\Frontend\Steps\WatchAssets;
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

/**
 * Run BOTH asset steps in pipeline order, like the shipped serve.steps —
 * exactly one of the two may act (or note) per configuration.
 */
function runAssetSteps(ServeContext $context): ServeContext
{
    app(BuildAssets::class)->handle($context, fn ($passed) => $passed);
    app(WatchAssets::class)->handle($context, fn ($passed) => $passed);

    return $context;
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

test('skips with ONE note when assets are disabled by flag', function (): void {
    Process::fake();
    bindAssetServices($this->dir);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"dev":"vite"}}');

    runAssetSteps(new ServeContext(new ServeOptions(withAssets: false)));

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('--without-assets');
    expect(substr_count(Prompt::strippedContent(), '--without-assets'))->toBe(1);
});

test('skips with a note when the configured mode is skip', function (): void {
    Process::fake();
    bindAssetServices($this->dir, assets: AssetMode::Skip);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"dev":"vite"}}');

    runAssetSteps(new ServeContext(new ServeOptions));

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('disabled in configuration');
    expect(substr_count(Prompt::strippedContent(), 'disabled in configuration'))->toBe(1);
});

test('skips with a note when no package.json exists', function (): void {
    Process::fake();
    bindAssetServices($this->dir);

    runAssetSteps(new ServeContext(new ServeOptions));

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('No package.json found');
});

test('build mode runs the build script synchronously and starts no watcher', function (): void {
    Process::fake(['*' => Process::result()]);
    $ledger = bindAssetServices($this->dir, assets: AssetMode::Build);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"build":"vite build","dev":"vite"}}');

    runAssetSteps(new ServeContext(new ServeOptions));

    Process::assertRan(fn ($process): bool => implode(' ', $process->command) === 'bun run build');
    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', $process->command), 'nohup'));
    expect($ledger->withLabel(WatchAssets::LABEL))->toBeEmpty();
});

test('build mode skips with a note when package.json has no build script', function (): void {
    Process::fake();
    bindAssetServices($this->dir, assets: AssetMode::Build);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"dev":"vite"}}');

    runAssetSteps(new ServeContext(new ServeOptions));

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains("no 'build' script");
});

test('watch mode skips with a note when package.json has no dev script', function (): void {
    Process::fake();
    bindAssetServices($this->dir);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"build":"vite build"}}');

    runAssetSteps(new ServeContext(new ServeOptions));

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains("no 'dev' script");
});

test('watch mode spawns a tracked assets-watch background process without a timeout, and no build', function (): void {
    Process::fake(['*' => Process::result(output: "77\n")]);
    $ledger = bindAssetServices($this->dir);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"build":"vite build","dev":"vite"}}');

    runAssetSteps(new ServeContext(new ServeOptions));

    Process::assertRan(function ($process): bool {
        $command = implode(' ', $process->command);

        return str_contains($command, 'nohup bun run dev')
            && str_contains($command, 'assets-watch.log')
            && $process->timeout === null;
    });
    Process::assertDidntRun(fn ($process): bool => implode(' ', $process->command) === 'bun run build');

    $records = $ledger->withLabel(WatchAssets::LABEL);

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

    $ledger->record(new ProcessRecord(pid: 77, label: WatchAssets::LABEL, command: 'bun run dev', startedAt: date(DATE_ATOM)));

    runAssetSteps(new ServeContext(new ServeOptions));

    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', $process->command), 'nohup'));

    expect($ledger->withLabel(WatchAssets::LABEL))->toHaveCount(1);
    Prompt::assertStrippedOutputContains('already running');
});
