<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Config\DevServerConfig;
use Igne\LaravelBootUp\Config\ToolsConfig;
use Igne\LaravelBootUp\Contracts\InstallsTool;
use Igne\LaravelBootUp\Exceptions\ConfigException;
use Igne\LaravelBootUp\Exceptions\ServerException;
use Igne\LaravelBootUp\Servers\ServerSelector;
use Igne\LaravelBootUp\Tools\ToolRegistry;

/*
 * These maps are checked where they resolve rather than when the config object
 * is built: nothing has been mutated by then, so an unrelated command does not
 * need to fail over a key it never reads.
 */
test('a driver class that is not a Server is rejected when it is selected', function (): void {
    $selector = new ServerSelector(app(), new DevServerConfig(drivers: ['broken' => stdClass::class]));

    expect(fn () => $selector->driver('broken'))
        ->toThrow(ConfigException::class, 'boot-up.server.drivers.broken');
});

test('a driver class that does not exist is rejected when it is selected', function (): void {
    $selector = new ServerSelector(app(), new DevServerConfig(drivers: ['ghost' => 'App\\Servers\\Ghost']));

    expect(fn () => $selector->driver('ghost'))
        ->toThrow(ConfigException::class, 'App\\Servers\\Ghost');
});

test('an unknown driver key still reports the key, not a class', function (): void {
    $selector = new ServerSelector(app(), new DevServerConfig);

    expect(fn () => $selector->driver('nginx'))
        ->toThrow(ServerException::class, 'nginx');
});

test('the built-in drivers resolve without a published config', function (): void {
    $selector = new ServerSelector(app(), new DevServerConfig);

    expect($selector->driver('herd')->key())->toBe('herd')
        ->and($selector->driver('sail')->key())->toBe('sail')
        ->and($selector->driver('artisan')->key())->toBe('artisan');
});

test('a custom installer that is not an InstallsTool is rejected when it is asked for', function (): void {
    $registry = new ToolRegistry(app(), new ToolsConfig(installers: ['php' => stdClass::class]));

    expect(fn () => $registry->installerFor('php'))
        ->toThrow(ConfigException::class, 'boot-up.tools.installers.php');
});

test('the built-in installers are untouched by the check', function (): void {
    $registry = new ToolRegistry(app(), new ToolsConfig);

    expect($registry->installerFor('php'))->toBeInstanceOf(InstallsTool::class);
});

test('the reaper is bound with the configured grace periods, not the defaults', function (): void {
    config()->set('boot-up.process.term_grace_seconds', 11);
    config()->set('boot-up.process.kill_grace_seconds', 7);
    app()->forgetInstance(Igne\LaravelBootUp\Config\ProcessConfig::class);
    app()->forgetInstance(Igne\LaravelBootUp\Process\ProcessReaper::class);

    $reaper = app(Igne\LaravelBootUp\Process\ProcessReaper::class);
    $read = fn (string $property): int => (new ReflectionProperty($reaper, $property))->getValue($reaper);

    expect($read('termGraceSeconds'))->toBe(11)
        ->and($read('killGraceSeconds'))->toBe(7);
});
