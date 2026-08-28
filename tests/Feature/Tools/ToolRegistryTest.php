<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\ToolsConfig;
use Igne\LaravelBootUp\Exceptions\ToolException;
use Igne\LaravelBootUp\Process\ProcessLedger;
use Igne\LaravelBootUp\Process\ProcessRunner;
use Igne\LaravelBootUp\Tests\Feature\Tools\Fixtures\RegistryCustomToolSpy;
use Igne\LaravelBootUp\Tools\Installers\ComposerInstaller;
use Igne\LaravelBootUp\Tools\Installers\HomebrewInstaller;
use Igne\LaravelBootUp\Tools\Installers\PackageManagerInstaller;
use Igne\LaravelBootUp\Tools\Installers\PhpInstaller;
use Igne\LaravelBootUp\Tools\ToolRegistry;
use Illuminate\Process\Factory;

beforeEach(function (): void {
    $this->workDir = sys_get_temp_dir().'/boot-up-registry-test-'.bin2hex(random_bytes(4));
    mkdir($this->workDir, 0755, true);

    $this->app->instance(ProcessRunner::class, new ProcessRunner(
        processes: app(Factory::class),
        ledger: new ProcessLedger($this->workDir.'/processes.json'),
        logDirectory: $this->workDir.'/logs',
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
    'node' => ['node', HomebrewInstaller::class],
    'composer' => ['composer', ComposerInstaller::class],
    'docker' => ['docker', HomebrewInstaller::class],
    'herd' => ['herd', HomebrewInstaller::class],
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

test('homebrew has no standalone installer — it installs itself', function (): void {
    makeRegistry()->installerFor('homebrew');
})->throws(ToolException::class);
