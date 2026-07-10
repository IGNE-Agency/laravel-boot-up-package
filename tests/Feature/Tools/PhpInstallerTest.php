<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Process\ProcessLedger;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\Terminal\NullTerminal;
use Igne\LaravelBootstrap\Support\Poller;
use Igne\LaravelBootstrap\Tools\Installers\Homebrew;
use Igne\LaravelBootstrap\Tools\Installers\PhpInstaller;
use Igne\LaravelBootstrap\Tools\ToolInspector;
use Igne\LaravelBootstrap\Tools\VersionConstraint;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/bootstrap-php-installer-test-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

function makePhpInstaller(string $workDir): PhpInstaller
{
    $runner = new ProcessRunner(
        processes: app(Factory::class),
        ledger: new ProcessLedger($workDir.'/processes.json'),
        terminal: new NullTerminal,
        poller: new Poller,
        logDirectory: $workDir.'/logs',
        runtimeDirectory: $workDir.'/runtime',
    );

    return new PhpInstaller(new ToolInspector($runner), new Homebrew($runner), $runner);
}

function phpInstallerCommand(object $process): string
{
    return is_array($process->command) ? implode(' ', $process->command) : $process->command;
}

test('install goes through herd when the herd binary is available', function (): void {
    Process::fake(['*' => Process::result()]);

    makePhpInstaller($this->workDir)->install(VersionConstraint::wildcard());

    Process::assertRan(fn ($process): bool => phpInstallerCommand($process) === 'herd php:install');
    Process::assertDidntRun(fn ($process): bool => str_contains(phpInstallerCommand($process), 'brew install php'));
});

test('install falls back to brew when herd is not available', function (): void {
    Process::fake([
        '*command -v*herd*' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    makePhpInstaller($this->workDir)->install(VersionConstraint::wildcard());

    Process::assertRan(fn ($process): bool => phpInstallerCommand($process) === 'brew install php');
    Process::assertDidntRun(fn ($process): bool => phpInstallerCommand($process) === 'herd php:install');
});

test('update goes through herd when the herd binary is available', function (): void {
    Process::fake(['*' => Process::result()]);

    makePhpInstaller($this->workDir)->update(VersionConstraint::of('^8.3'));

    Process::assertRan(fn ($process): bool => phpInstallerCommand($process) === 'herd php:install');
});

test('update upgrades via brew when herd is not available', function (): void {
    Process::fake([
        '*command -v*herd*' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    makePhpInstaller($this->workDir)->update(VersionConstraint::of('^8.3'));

    Process::assertRan(fn ($process): bool => phpInstallerCommand($process) === 'brew upgrade php');
});
