<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\Steps\BuildAssets;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

/**
 * The asset watcher is a dev process now, started from the registry after the
 * boot, so this covers the one asset step that survives: the synchronous
 * build. The watcher's own gates live in DevProcessRegistrarTest.
 *
 * Call AFTER Process::fake() so the runner receives the faked factory.
 */
function bindAssetServices(string $dir, AssetMode $assets = AssetMode::Build): void
{
    $ledger = new ProcessLedger($dir.'/processes.json');

    app()->instance(PackageJson::class, new PackageJson($dir.'/package.json'));
    app()->instance(FrontendConfig::class, new FrontendConfig(PackageManager::Bun, $assets));
    app()->instance(ProcessLedger::class, $ledger);
    app()->instance(ProcessRunner::class, new ProcessRunner(
        processes: app(Factory::class),
        ledger: $ledger,
        logDirectory: $dir.'/logs',
    ));
}

function runBuildStep(ServeContext $context): ServeContext
{
    app(BuildAssets::class)->handle($context, fn ($passed) => $passed);

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

test('builds the assets synchronously', function (): void {
    Process::fake(['*' => Process::result()]);
    bindAssetServices($this->dir);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"build":"vite build","dev":"vite"}}');

    runBuildStep(new ServeContext(new ServeOptions));

    Process::assertRan(fn ($process): bool => implode(' ', $process->command) === 'bun run build');
    Process::assertDidntRun(fn ($process): bool => str_contains(implode(' ', $process->command), 'nohup'));
});

test('skips with a note when assets are disabled by flag', function (): void {
    Process::fake();
    bindAssetServices($this->dir);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"build":"vite build"}}');

    runBuildStep(new ServeContext(new ServeOptions(withAssets: false)));

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('--without-assets');
});

test('skips with a note when the configured mode is skip', function (): void {
    Process::fake();
    bindAssetServices($this->dir, assets: AssetMode::Skip);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"build":"vite build"}}');

    runBuildStep(new ServeContext(new ServeOptions));

    Process::assertNothingRan();
});

test('skips with a note when no package.json exists', function (): void {
    Process::fake();
    bindAssetServices($this->dir);

    runBuildStep(new ServeContext(new ServeOptions));

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('No package.json found');
});

test('skips with a note when package.json has no build script', function (): void {
    Process::fake();
    bindAssetServices($this->dir);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"dev":"vite"}}');

    runBuildStep(new ServeContext(new ServeOptions));

    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains("no 'build' script");
});

test('stays quiet in watch mode, where the watcher runs as a dev process', function (): void {
    Process::fake();
    bindAssetServices($this->dir, assets: AssetMode::Watch);
    file_put_contents($this->dir.'/package.json', '{"scripts":{"build":"vite build","dev":"vite"}}');

    runBuildStep(new ServeContext(new ServeOptions));

    Process::assertNothingRan();
    expect(Prompt::strippedContent())->toBe('');
});
