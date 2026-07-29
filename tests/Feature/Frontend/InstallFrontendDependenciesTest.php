<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Contracts\RewritesCommands;
use Igne\LaravelBootUp\Contracts\Server;
use Igne\LaravelBootUp\Data\CommandRewrites;
use Igne\LaravelBootUp\Data\ServeContext;
use Igne\LaravelBootUp\Data\ServeOptions;
use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Enums\RunMode;
use Igne\LaravelBootUp\Exceptions\FrontendException;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\Steps\InstallFrontendDependencies;
use Igne\LaravelBootUp\Process\NullTerminalLauncher;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Services\Poller;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

/**
 * Call AFTER Process::fake() so the runner receives the faked factory.
 */
function bindFrontendInstallServices(string $dir): void
{
    app()->instance(PackageJson::class, new PackageJson($dir.'/package.json'));
    app()->instance(FrontendConfig::class, new FrontendConfig(PackageManager::BUN, 'watch', RunMode::Background));
    app()->instance(ProcessRunner::class, new ProcessRunner(
        processes: app(Factory::class),
        ledger: new ProcessLedger($dir.'/processes.json'),
        terminal: new NullTerminalLauncher,
        poller: new Poller,
        logDirectory: $dir.'/logs',
        runtimeDirectory: $dir.'/runtime',
    ));
}

function frontendSailServer(): Server
{
    return new class implements RewritesCommands, Server
    {
        public function key(): string
        {
            return 'sail';
        }

        public function label(): string
        {
            return 'Laravel Sail';
        }

        public function commandRewrites(): CommandRewrites
        {
            return new CommandRewrites(
                replaces: ['php artisan' => 'artisan'],
                prefixes: ['php', 'composer', 'yarn', 'npm', 'bun', 'artisan', 'node'],
                prefix: './vendor/bin/sail',
            );
        }

        public function isRunning(): bool
        {
            return true;
        }

        public function start(ServeContext $context): void {}

        public function stop(): void {}

        public function url(): string
        {
            return 'http://localhost';
        }
    };
}

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-frontend-install-'.bin2hex(random_bytes(4));
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
    bindFrontendInstallServices($this->dir);
    file_put_contents($this->dir.'/package.json', '{}');

    $context = new ServeContext(new ServeOptions(withAssets: false));

    $result = app(InstallFrontendDependencies::class)->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('--without-assets');
});

test('skips with a note when no package.json exists', function (): void {
    Process::fake();
    bindFrontendInstallServices($this->dir);

    $context = new ServeContext(new ServeOptions);

    $result = app(InstallFrontendDependencies::class)->handle($context, fn ($passed) => $passed);

    expect($result)->toBe($context);
    Process::assertNothingRan();
    Prompt::assertStrippedOutputContains('No package.json found');
});

test('runs the selected package manager install command', function (): void {
    Process::fake(['*' => Process::result()]);
    bindFrontendInstallServices($this->dir);
    file_put_contents($this->dir.'/package.json', '{}');

    app(InstallFrontendDependencies::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertRan(fn ($process): bool => implode(' ', $process->command) === 'bun install');
});

test('the update flag switches to the update command', function (): void {
    Process::fake(['*' => Process::result()]);
    bindFrontendInstallServices($this->dir);
    file_put_contents($this->dir.'/package.json', '{}');

    $context = new ServeContext(new ServeOptions(update: true));

    app(InstallFrontendDependencies::class)->handle($context, fn ($passed) => $passed);

    Process::assertRan(fn ($process): bool => implode(' ', $process->command) === 'bun update');
});

test('the install command is rewritten for the active server', function (): void {
    Process::fake(['*' => Process::result()]);
    bindFrontendInstallServices($this->dir);
    file_put_contents($this->dir.'/package.json', '{}');

    $context = new ServeContext(new ServeOptions, frontendSailServer());

    app(InstallFrontendDependencies::class)->handle($context, fn ($passed) => $passed);

    Process::assertRan(fn ($process): bool => implode(' ', $process->command) === './vendor/bin/sail bun install');
});

test('a lockfile conflict triggers a warning and exactly one retry', function (): void {
    Process::fake([
        '*' => Process::sequence()
            ->push(Process::result(errorOutput: 'error: lockfile had changes, but lockfile is frozen', exitCode: 1))
            ->push(Process::result()),
    ]);
    bindFrontendInstallServices($this->dir);
    file_put_contents($this->dir.'/package.json', '{}');

    app(InstallFrontendDependencies::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);

    Process::assertRanTimes(fn ($process): bool => implode(' ', $process->command) === 'bun install', 2);
    Prompt::assertStrippedOutputContains('retrying once');
});

test('a non-lockfile failure is wrapped in a FrontendException', function (): void {
    Process::fake(['*' => Process::result(errorOutput: 'ENOTFOUND registry.npmjs.org', exitCode: 1)]);
    bindFrontendInstallServices($this->dir);
    file_put_contents($this->dir.'/package.json', '{}');

    app(InstallFrontendDependencies::class)->handle(new ServeContext(new ServeOptions), fn ($passed) => $passed);
})->throws(FrontendException::class, 'ENOTFOUND');
