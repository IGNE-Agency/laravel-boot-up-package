<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Tests\Concerns\InteractsWithDevCommands;
use Illuminate\Foundation\DevCommand;
use Illuminate\Foundation\DevCommands;

uses(InteractsWithDevCommands::class);

/*
 * Laravel's dev process registry is entirely static and ships no reset hook, so
 * without the teardown in TestCase every registration, filter and display setting
 * would bleed into the tests that follow. These tests run in file order and are
 * the guard on that: the polluting test sits between two that assert a clean slate.
 */
it('starts each test from the framework defaults', function (): void {
    expect($this->devCommandNames())->toBe(['server', 'queue', 'logs', 'vite']);
});

it('registers, filters and reconfigures the registry', function (): void {
    DevCommands::artisan('leaked:marker', 'leak-marker');
    DevCommands::except('vite');
    DevCommands::stream();
    DevCommands::withTimestamps();
    DevCommands::disableAutoRestart();
    DevCommands::bufferSize(11);

    expect($this->devCommandNames())
        ->toContain('leak-marker')
        ->not->toContain('vite');
});

it('leaks none of that into the next test', function (): void {
    expect($this->devCommandNames())->toBe(['server', 'queue', 'logs', 'vite'])
        ->and(DevCommands::mode())->toBe(Illuminate\Foundation\DevCommandMode::TABS)
        ->and(DevCommands::shouldIncludeTimestamps())->toBeFalse()
        ->and(DevCommands::shouldAutoRestart())->toBeTrue()
        ->and(DevCommands::getBufferSize())->toBeNull()
        ->and(DevCommands::getStreamBufferSize())->toBeNull();
});

it('seeds a registration at a priority the backtrace could not produce', function (): void {
    $this->seedDevCommand('php artisan octane:start --watch', 'server', DevCommand::PRIORITY_VENDOR);

    expect($this->devCommand('server'))
        ->priority->toBe(DevCommand::PRIORITY_VENDOR)
        ->command->toBe('php artisan octane:start --watch');
});

it('leaks no seeded registration either', function (): void {
    expect($this->devCommand('server'))
        ->priority->toBe(DevCommand::PRIORITY_DEFAULT)
        ->command->toContain('serve');
});
