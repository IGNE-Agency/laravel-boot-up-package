<?php

declare(strict_types=1);

use Igne\LaravelBootstrap\Process\ProcessLedger;
use Igne\LaravelBootstrap\Process\ProcessRunner;
use Igne\LaravelBootstrap\Process\Terminal\NullTerminal;
use Igne\LaravelBootstrap\Support\Poller;
use Igne\LaravelBootstrap\Tests\Feature\Tools\Fixtures\RegistryCustomToolSpy;
use Igne\LaravelBootstrap\Tools\Installers\ComposerInstaller;
use Igne\LaravelBootstrap\Tools\Installers\DockerInstaller;
use Igne\LaravelBootstrap\Tools\Installers\HerdInstaller;
use Igne\LaravelBootstrap\Tools\Installers\NodeInstaller;
use Igne\LaravelBootstrap\Tools\Installers\PackageManagerInstaller;
use Igne\LaravelBootstrap\Tools\Installers\PhpInstaller;
use Igne\LaravelBootstrap\Tools\ToolException;
use Igne\LaravelBootstrap\Tools\ToolRegistry;
use Igne\LaravelBootstrap\Tools\ToolsConfig;
use Illuminate\Process\Factory;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/bootstrap-registry-test-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);

    $this->app->instance(ProcessRunner::class, new ProcessRunner(
        processes: app(Factory::class),
        ledger: new ProcessLedger($this->workDir.'/processes.json'),
        terminal: new NullTerminal,
        poller: new Poller,
        logDirectory: $this->workDir.'/logs',
        runtimeDirectory: $this->workDir.'/runtime',
    ));
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->workDir));
});

function makeRegistry(array $installers = []): ToolRegistry
{
    return new ToolRegistry(app(), new ToolsConfig(
        autoInstall: true,
        autoUpdate: true,
        required: [],
        installers: $installers,
    ));
}

test('resolves the built-in installer for each known tool id', function (string $id, string $class): void {
    $installer = makeRegistry()->installerFor($id);

    expect($installer)->toBeInstanceOf($class)
        ->and($installer->id())->toBe($id);
})->with([
    'php' => ['php', PhpInstaller::class],
    'node' => ['node', NodeInstaller::class],
    'composer' => ['composer', ComposerInstaller::class],
    'docker' => ['docker', DockerInstaller::class],
    'herd' => ['herd', HerdInstaller::class],
    'bun' => ['bun', PackageManagerInstaller::class],
    'yarn' => ['yarn', PackageManagerInstaller::class],
    'npm' => ['npm', PackageManagerInstaller::class],
    'pnpm' => ['pnpm', PackageManagerInstaller::class],
]);

test('a configured installer wins over the built-in for the same id', function (): void {
    $registry = makeRegistry(['php' => RegistryCustomToolSpy::class]);

    expect($registry->installerFor('php'))->toBeInstanceOf(RegistryCustomToolSpy::class);
});

test('a configured installer enables entirely custom tool ids', function (): void {
    $registry = makeRegistry(['mytool' => RegistryCustomToolSpy::class]);

    expect($registry->installerFor('mytool'))->toBeInstanceOf(RegistryCustomToolSpy::class);
});

test('an unknown tool id without a custom installer throws', function (): void {
    makeRegistry()->installerFor('ghost');
})->throws(ToolException::class, "No installer is known for tool 'ghost'");

test('homebrew has no standalone installer — it bootstraps itself', function (): void {
    makeRegistry()->installerFor('homebrew');
})->throws(ToolException::class);
