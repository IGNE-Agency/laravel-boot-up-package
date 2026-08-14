<?php

declare(strict_types=1);

use Igne\LaravelBootUp\Enums\RegistrationSource;
use Igne\LaravelBootUp\Exceptions\BootCommandException;
use Igne\LaravelBootUp\Serve\BootCommandRegistry;
use Igne\LaravelBootUp\Serve\PendingBootProcess;

function registry(bool $console = true, string $vendorPath = '/nonexistent/vendor'): BootCommandRegistry
{
    return new BootCommandRegistry(runningInConsole: $console, vendorPath: $vendorPath);
}

test('names default to the command, explicit names win', function (): void {
    $registry = registry();

    $registry->register('stripe listen --forward-to http://localhost');
    $registry->artisan('reverb:start --debug');
    $registry->packageManager('run dev');
    $registry->packageManagerExec('vite --port 3000', 'assets');

    expect(array_map(fn (PendingBootProcess $process): string => $process->name(), $registry->launchable()))
        ->toBe(['stripe', 'reverb:start', 'dev', 'assets']);
});

test('a same-name registration replaces by source rank', function (): void {
    $registry = registry();

    $registry->register('reverb-cli start', 'reverb', RegistrationSource::Vendor)->pink();
    $first = $registry->launchable()[0];

    // Vendor cannot displace the application...
    $registry->artisan('reverb:start', 'reverb', RegistrationSource::Application);
    $registry->register('other reverb', 'reverb', RegistrationSource::Vendor);

    $survivor = $registry->launchable()[0];

    expect($survivor)->not->toBe($first)
        ->and($survivor->source())->toBe(RegistrationSource::Application);

    // ...but a later application registration replaces an earlier one.
    $registry->register('newest', 'reverb', RegistrationSource::Application);

    expect($registry->launchable())->toHaveCount(1)
        ->and($registry->launchable()[0])->not->toBe($survivor);
});

test('a replacement keeps the original slot in registration order', function (): void {
    $registry = registry();

    $registry->register('one', 'first', RegistrationSource::Application);
    $registry->register('two', 'second', RegistrationSource::Application);
    $registry->register('three', 'first', RegistrationSource::Application);

    expect(array_map(fn (PendingBootProcess $process): string => $process->name(), $registry->launchable()))
        ->toBe(['first', 'second']);
});

test('only and except filter and merge across calls', function (): void {
    $registry = registry();

    $registry->register('stripe listen', source: RegistrationSource::Application);
    $registry->register('ngrok http 80', source: RegistrationSource::Application);

    $registry->only('stripe');
    $registry->only('queue');
    $registry->except('vite');
    $registry->except('horizon');

    expect($registry->allows('stripe'))->toBeTrue()
        ->and($registry->allows('queue'))->toBeTrue()
        ->and($registry->allows('ngrok'))->toBeFalse()
        ->and($registry->allows('vite'))->toBeFalse()
        ->and(array_map(fn (PendingBootProcess $process): string => $process->name(), $registry->launchable()))->toBe(['stripe'])
        ->and(array_map(fn (PendingBootProcess $process): string => $process->name(), $registry->suppressed()))->toBe(['ngrok']);
});

test('except beats only for the same name', function (): void {
    $registry = registry();

    $registry->only('queue');
    $registry->except('queue');

    expect($registry->allows('queue'))->toBeFalse();
});

test('a registration under a built-in stream name replaces it, unless filtered out', function (): void {
    $registry = registry();

    $registry->artisan('reverb:start --host=0.0.0.0', 'reverb', RegistrationSource::Application);

    expect($registry->replaces('reverb'))->toBeTrue()
        ->and($registry->replaces('queue'))->toBeFalse();

    $registry->except('reverb');

    expect($registry->replaces('reverb'))->toBeFalse();
});

test('summary labels mark built-in replacements', function (): void {
    $registry = registry();

    $registry->register('stripe listen', source: RegistrationSource::Application);
    $registry->artisan('reverb:start', 'reverb', RegistrationSource::Application);

    expect($registry->summaryLabels())->toBe(['stripe', 'reverb (replaces built-in)']);
});

test('the server stream name is reserved', function (): void {
    registry()->register('php -S localhost:9000', 'server', RegistrationSource::Application);
})->throws(BootCommandException::class, 'reserved for the development server');

test('outside the console registrations stay fluent but inert', function (): void {
    $registry = registry(console: false);

    $process = $registry->register('stripe listen', source: RegistrationSource::Application)->orange()->first();
    $registry->only('stripe');
    $registry->except('queue');

    expect($process)->toBeInstanceOf(PendingBootProcess::class)
        ->and($registry->isEmpty())->toBeTrue()
        ->and($registry->allows('queue'))->toBeTrue();
});

test('the backtrace decides the source: this file counts as vendor when vendorPath contains it', function (): void {
    $application = registry()->register('stripe listen');
    $vendor = registry(vendorPath: dirname(__DIR__, 2))->register('stripe listen');

    expect($application->source())->toBe(RegistrationSource::Application)
        ->and($vendor->source())->toBe(RegistrationSource::Vendor);
});
