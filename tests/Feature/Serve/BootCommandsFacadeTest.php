<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Enums\RegistrationSource;
use Igne\LaravelBootUp\Facades\BootCommands;
use Igne\LaravelBootUp\Serve\BootCommandRegistry;

test('the facade fronts one registry singleton', function (): void {
    BootCommands::artisan('reverb:start', 'reverb')->orange()->after('queue');

    $registry = app(BootCommandRegistry::class);

    expect($registry->replaces('reverb'))->toBeTrue()
        ->and($registry->launchable()[0]->name())->toBe('reverb');
});

test('test code registers as application code', function (): void {
    $process = BootCommands::register('stripe listen');

    expect($process->source())->toBe(RegistrationSource::Application);
});
