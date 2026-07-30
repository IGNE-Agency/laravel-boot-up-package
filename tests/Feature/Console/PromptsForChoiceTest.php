<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\FlavorCommand;
use Igne\LaravelBootUp\Tests\Feature\Console\Fixtures\OptionlessChoiceCommand;
use Illuminate\Contracts\Console\Kernel;

beforeEach(function (): void {
    app(Kernel::class)->registerCommand(new FlavorCommand);
    app(Kernel::class)->registerCommand(new OptionlessChoiceCommand);
});

test('a supplied argument is matched case-insensitively without prompting', function (): void {
    $this->artisan('test:flavor', ['flavor' => 'CHOCOLATE'])
        ->expectsOutputToContain('Chose chocolate.')
        ->assertSuccessful();
});

test('a missing argument falls back to an interactive select', function (): void {
    $this->artisan('test:flavor')
        ->expectsQuestion('Which flavor?', 'chocolate')
        ->expectsOutputToContain('Chose chocolate.')
        ->assertSuccessful();
});

test('an unknown argument fails cleanly with the available options', function (): void {
    $this->artisan('test:flavor', ['flavor' => 'mint'])
        ->expectsOutputToContain('Unknown flavor [mint]. Available: vanilla, chocolate')
        ->assertFailed();
});

test('a choice without its options method is reported as a developer error', function (): void {
    $this->artisan('test:optionless')
        ->expectsOutputToContain('must define flavorOptions() to choose a flavor')
        ->assertFailed();
});
