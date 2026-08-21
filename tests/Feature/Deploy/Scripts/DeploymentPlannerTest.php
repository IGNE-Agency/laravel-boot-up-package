<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Boot\HorizonPresence;
use Igne\LaravelBootUp\Config\DatabaseConfig;
use Igne\LaravelBootUp\Config\DeployConfig;
use Igne\LaravelBootUp\Config\FrontendConfig;
use Igne\LaravelBootUp\Config\HorizonConfig;
use Igne\LaravelBootUp\Config\QueueConfig;
use Igne\LaravelBootUp\Config\ReverbConfig;
use Igne\LaravelBootUp\Deploy\Scripts\DeploymentPlanner;
use Igne\LaravelBootUp\Enums\AssetMode;
use Igne\LaravelBootUp\Enums\BuiltInProcess;
use Igne\LaravelBootUp\Enums\DeploymentEnvironment;
use Igne\LaravelBootUp\Enums\PackageManager;
use Igne\LaravelBootUp\Frontend\PackageJson;
use Igne\LaravelBootUp\Frontend\PackageManagerSelector;
use Igne\LaravelBootUp\Pipelines\ComposerJson;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/boot-up-planner-'.bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);
    file_put_contents($this->dir.'/package.json', '{}');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

/**
 * @param  list<string>  $requires
 */
function planner(
    string $dir,
    array $requires = [],
    ?QueueConfig $queue = null,
    ?ReverbConfig $reverb = null,
    ?HorizonConfig $horizon = null,
    ?FrontendConfig $frontend = null,
): DeploymentPlanner {
    file_put_contents($dir.'/composer.json', json_encode(['require' => array_fill_keys($requires, '*')]));

    $composerJson = new ComposerJson($dir.'/composer.json');
    $frontend ??= new FrontendConfig(packageManager: PackageManager::Npm);

    return new DeploymentPlanner(
        container: app(),
        deploy: new DeployConfig,
        database: new DatabaseConfig,
        frontend: $frontend,
        queue: $queue ?? new QueueConfig,
        reverb: $reverb ?? new ReverbConfig,
        horizon: new HorizonPresence($horizon ?? new HorizonConfig, $composerJson),
        composerJson: $composerJson,
        packageManagers: new PackageManagerSelector($frontend, new PackageJson($dir.'/package.json')),
    );
}

function restartsFor(string $dir, mixed ...$arguments): array
{
    return planner($dir, ...$arguments)->plan(DeploymentEnvironment::Production)->restarts;
}

test('an ordinary project restarts its queue workers', function (): void {
    expect(restartsFor($this->dir))->toBe([BuiltInProcess::Queue]);
});

test('a Horizon project terminates Horizon instead of restarting the queue', function (): void {
    // Horizon supervises its own workers and does not answer queue:restart,
    // so sending both -- or the wrong one -- leaves stale code running.
    expect(restartsFor($this->dir, requires: ['laravel/horizon']))->toBe([BuiltInProcess::Horizon]);
});

test('a Horizon project disabled in configuration falls back to the queue', function (): void {
    $restarts = restartsFor($this->dir, requires: ['laravel/horizon'], horizon: new HorizonConfig(enabled: false));

    expect($restarts)->toBe([BuiltInProcess::Queue]);
});

test('a Reverb project restarts Reverb as well', function (): void {
    expect(restartsFor($this->dir, requires: ['laravel/reverb']))
        ->toBe([BuiltInProcess::Queue, BuiltInProcess::Reverb]);
});

test('a Reverb project disabled in configuration does not', function (): void {
    $restarts = restartsFor($this->dir, requires: ['laravel/reverb'], reverb: new ReverbConfig(enabled: false));

    expect($restarts)->toBe([BuiltInProcess::Queue]);
});

test('restarts read in the order the processes run', function (): void {
    $restarts = restartsFor($this->dir, requires: ['laravel/horizon', 'laravel/reverb']);

    expect($restarts)->toBe([BuiltInProcess::Horizon, BuiltInProcess::Reverb]);
});

test('a project with the queue turned off restarts nothing', function (): void {
    expect(restartsFor($this->dir, queue: new QueueConfig(enabled: false)))->toBe([]);
});

test('every restart names a command to run', function (): void {
    $restarts = restartsFor($this->dir, requires: ['laravel/horizon', 'laravel/reverb']);

    foreach ($restarts as $process) {
        expect($process->restartCommand())->not->toBeNull();
    }
});

test('the frontend flag follows the asset mode, not a string', function (): void {
    $skip = new FrontendConfig(packageManager: PackageManager::Npm, assets: AssetMode::Skip);

    expect(planner($this->dir, frontend: $skip)->plan(DeploymentEnvironment::Production)->frontend)->toBeFalse()
        ->and(planner($this->dir)->plan(DeploymentEnvironment::Production)->frontend)->toBeTrue();
});
